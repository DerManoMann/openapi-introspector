<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Integrations\L5Swagger;

use L5Swagger\ConfigFactory;
use L5Swagger\Generator as L5Generator;
use OpenApi\Analysers\AnalyserInterface;
use OpenApi\Analysers\AttributeAnnotationFactory;
use OpenApi\Analysers\ReflectionAnalyser;
use OpenApi\Builder\Mode;
use OpenApi\Contracts\PluginInterface;
use OpenApi\Utils\SourceFinder;
use Radebatz\OpenApi\Introspector\RoutesPlugin;
use Radebatz\OpenApi\Introspector\Routing\Laravel\LaravelAdapter;

/**
 * What an L5-Swagger documentation configures that decides the inventory: the sources it scans, the
 * mode it builds in, and the settings it gives the Laravel adapter.
 *
 * The inventory depends on which routes are read and which attributes are found, so a build that
 * follows these records what L5-Swagger's own build would.
 *
 * @internal
 */
class Documentation
{
    /**
     * @param array<string, mixed> $config the documentation's configuration, merged with L5-Swagger's defaults
     */
    public function __construct(protected array $config)
    {
    }

    /**
     * The named documentation, L5-Swagger's default where no name is given.
     */
    public static function named(?string $name = null): self
    {
        return new self((new ConfigFactory())->documentationConfig($name));
    }

    /**
     * The `annotations` paths, with the `pattern` and `exclude` scan options, as L5-Swagger finds them.
     */
    public function sources(): SourceFinder
    {
        $paths = $this->config['paths'] ?? [];
        $scanOptions = $this->scanOptions();

        $annotations = $paths['annotations'] ?? [];
        $exclude = ($scanOptions['exclude'] ?? []) ?: ($paths['excludes'] ?? []);
        $pattern = ($scanOptions['pattern'] ?? null) ?: '*.php';

        return new SourceFinder(is_array($annotations) ? array_values($annotations) : $annotations, $exclude, $pattern);
    }

    /**
     * The constants L5-Swagger defines before it scans, which attributes may use.
     *
     * @return array<string, mixed>
     */
    public function constants(): array
    {
        $constants = $this->config['constants'] ?? [];

        return is_array($constants) ? array_filter($constants, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * The `mode` scan option, where it is one the introspector runs in, and hybrid otherwise.
     *
     * Classic mode builds no specification for the introspector to add to. Hybrid reads the
     * attributes classic does, so the inventory comes out the same. A `builder_factory` sets the
     * mode on its own builder, where it cannot be read, and this package's factory uses hybrid.
     */
    public function mode(): Mode
    {
        $mode = $this->scanOptions()['mode'] ?? null;

        return ($this->scanOptions()['builder_factory'] ?? null) === null && $mode === Mode::SPEC->value ? Mode::SPEC : Mode::HYBRID;
    }

    /**
     * The analyser L5-Swagger reads attributes with, where its own generator runs: the `analyser`
     * scan option, or attributes only. None where a `builder_factory` sets up the build, or in spec
     * mode, which reads no classic annotations.
     */
    public function analyser(): ?AnalyserInterface
    {
        if (($this->scanOptions()['builder_factory'] ?? null) !== null || $this->mode() === Mode::SPEC) {
            return null;
        }

        $analyser = $this->scanOptions()['analyser'] ?? null;

        return $analyser instanceof AnalyserInterface ? $analyser : new ReflectionAnalyser([new AttributeAnnotationFactory()]);
    }

    /**
     * Whether L5-Swagger's own build adds the routes, so the document it serves has them.
     *
     * It does with a {@see RoutesPlugin} in the `plugins` scan option outside classic mode, or with
     * this package's {@see BuilderFactory}. A `builder_factory` of a project's own may register the
     * introspector itself, which cannot be told from here, so it counts as adding them.
     */
    public function addsRoutes(): bool
    {
        if (($this->scanOptions()['builder_factory'] ?? null) !== null) {
            return true;
        }

        if (($this->scanOptions()['mode'] ?? 'classic') === Mode::CLASSIC->value || !interface_exists(PluginInterface::class)) {
            return false;
        }

        foreach ($this->scanOptions()['plugins'] ?? [] as $key => $value) {
            $plugin = is_string($key) ? $key : $value;
            if ((is_string($plugin) || is_object($plugin)) && is_a($plugin, RoutesPlugin::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The settings the `plugins` scan option gives the Laravel adapter, none where it names no
     * {@see RoutesPlugin}.
     *
     * @return array<string, mixed>
     */
    public function adapterSettings(): array
    {
        // before swagger-php 7.3 there are no plugins, and RoutesPlugin cannot be loaded
        if (!interface_exists(PluginInterface::class)) {
            return [];
        }

        foreach ($this->scanOptions()['plugins'] ?? [] as $plugin => $settings) {
            if (!is_string($plugin) || !is_array($settings) || !is_a($plugin, RoutesPlugin::class, true)) {
                continue;
            }

            foreach ($settings as $adapter => $adapterSettings) {
                if (is_string($adapter) && is_array($adapterSettings) && is_a($adapter, LaravelAdapter::class, true)) {
                    return $adapterSettings;
                }
            }
        }

        return [];
    }

    /**
     * The OpenAPI version L5-Swagger builds the document for.
     */
    public function version(): string
    {
        $version = $this->scanOptions()['open_api_spec_version'] ?? null;

        return is_string($version) && $version !== '' ? $version : L5Generator::OPEN_API_DEFAULT_SPEC_VERSION;
    }

    /**
     * @return array<string, mixed>
     */
    protected function scanOptions(): array
    {
        $scanOptions = $this->config['scanOptions'] ?? [];

        return is_array($scanOptions) ? $scanOptions : [];
    }
}
