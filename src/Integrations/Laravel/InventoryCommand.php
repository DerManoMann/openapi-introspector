<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Integrations\Laravel;

use Illuminate\Console\Command;
use L5Swagger\ConfigFactory;
use OpenApi\Analysers\AnalyserInterface;
use OpenApi\Builder;
use OpenApi\Builder\Mode;
use OpenApi\Generator;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Radebatz\OpenApi\Introspector\Integrations\L5Swagger\Documentation;
use Radebatz\OpenApi\Introspector\Routing\Inventory;
use Radebatz\OpenApi\Introspector\Routing\Inventory\Entry;
use Radebatz\OpenApi\Introspector\Routing\Laravel\LaravelAdapter;
use Radebatz\OpenApi\Introspector\Routing\RouteIntrospector;
use Radebatz\OpenApi\Introspector\Routing\Status;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the document and prints its inventory: which operations attributes describe, which routes
 * serve, and where the two disagree.
 *
 * `route:list` already lists the routes, so by default this lists only the entries that need
 * attention, `unrouted` and `conflict`, and counts the rest. With L5-Swagger installed, the build
 * follows one of its documentations, so the inventory is that of the document it serves where its
 * own build adds the routes.
 */
class InventoryCommand extends Command
{
    protected $signature = 'openapi:inventory
        {--documentation= : The L5-Swagger documentation to follow, its default where not given}
        {--status=* : List only entries with this status}
        {--all : List every entry}
        {--format=table : The output format, table or json}
        {--fail-on=* : Exit with an error when an entry has this status, such as unrouted}';

    protected $description = 'List which operations the attributes describe and which routes serve';

    public function handle(): int
    {
        $format = $this->option('format');
        if (!in_array($format, ['table', 'json'], true)) {
            $this->report(sprintf('Invalid format "%s". Expected table or json.', is_string($format) ? $format : get_debug_type($format)));

            return self::INVALID;
        }

        try {
            $statuses = $this->statuses('status');
            $failOn = $this->statuses('fail-on');
        } catch (\ValueError $valueError) {
            $this->report($valueError->getMessage());

            return self::INVALID;
        }

        if ($this->option('documentation') !== null && !$this->hasL5Swagger()) {
            $this->report('--documentation needs L5-Swagger and its configuration.');

            return self::INVALID;
        }

        $introspector = new RouteIntrospector();

        // the log goes where the errors go, so `--format=json` leaves the output parseable. Errors
        // logged before the inventory is recorded, such as a class skipped while scanning, mean
        // operations may be missing from it; later ones are about the document, not the inventory
        $logger = new class (new ConsoleLogger($this->errorOutput()), static fn (): bool => !self::introspected($introspector)) extends AbstractLogger {
            public int $errors = 0;

            public function __construct(protected LoggerInterface $logger, protected \Closure $counting)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if (in_array($level, [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR], true) && ($this->counting)()) {
                    ++$this->errors;
                }

                $this->logger->log($level, $message, $context);
            }
        };

        try {
            $inventory = $this->inventory($introspector, $logger);
        } catch (\Throwable $throwable) {
            $this->report($throwable->getMessage());

            return self::INVALID;
        }

        $entries = $statuses === []
            ? $inventory->entries()
            : array_values(array_filter($inventory->entries(), static fn (Entry $entry): bool => in_array($entry->status, $statuses, true)));

        if ($format === 'json') {
            $this->line((string) json_encode(array_map(static fn (Entry $entry): array => $entry->toArray(), $entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->summary($inventory, $entries, $statuses !== [] || (bool) $this->option('all'));
        }

        if ($inventory->entries() === []) {
            $this->report('The inventory is empty: no route was read and no attribute found.');
        }

        if ($logger->errors > 0) {
            $this->report(sprintf('The build logged %d %s, so the inventory may be incomplete.', $logger->errors, $logger->errors === 1 ? 'error' : 'errors'));

            return self::INVALID;
        }

        $failing = array_filter($inventory->entries(), static fn (Entry $entry): bool => in_array($entry->status, $failOn, true));
        if ($failing !== []) {
            $this->report(sprintf('%d %s', count($failing), count($failing) === 1 ? 'entry fails the check' : 'entries fail the check'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Builds the document with the introspector registered, and returns its inventory.
     */
    protected function inventory(RouteIntrospector $introspector, LoggerInterface $logger): Inventory
    {
        $documentation = $this->documentation();

        // attributes may use them, and a class that cannot be loaded is skipped
        foreach ($documentation?->constants() ?? [] as $name => $value) {
            defined($name) || define($name, $value);
        }

        $introspector->addAdapter(LaravelAdapter::fromConfig($documentation?->adapterSettings() ?? [], $logger));

        if ($documentation instanceof Documentation && !$documentation->addsRoutes()) {
            $this->report("L5-Swagger's own build does not add the routes, so the document it serves has none of them. The inventory shows what adding them would give.", 'comment');
        }

        $builder = $this->builder($documentation?->mode() ?? Mode::HYBRID, $logger)
            ->addSource($documentation?->sources() ?? app_path());

        // attributes only, as L5-Swagger reads them: the generator's default also reads docblock
        // annotations, which would count operations its document does not have
        $analyser = $documentation?->analyser();
        if ($analyser instanceof AnalyserInterface) {
            // deprecated in swagger-php 7, but still the only way to choose the analyser, and L5-Swagger's own
            $builder->withGenerator(static fn (Generator $generator): Generator => $generator->setAnalyser($analyser));
        }

        $introspector->register($builder)->build();

        return $introspector->inventory();
    }

    /**
     * Whether the build has recorded the inventory yet.
     */
    protected static function introspected(RouteIntrospector $introspector): bool
    {
        try {
            $introspector->inventory();

            return true;
        } catch (\LogicException) {
            return false;
        }
    }

    /**
     * Writes a message to the error output, so `--format=json` leaves the output parseable.
     */
    protected function report(string $message, string $style = 'error'): void
    {
        $this->errorOutput()->writeln(sprintf('<%s>%s</%s>', $style, OutputFormatter::escape($message), $style));
    }

    protected function errorOutput(): OutputInterface
    {
        $output = $this->output->getOutput();

        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * The L5-Swagger documentation the build follows, none where L5-Swagger is not installed.
     */
    protected function documentation(): ?Documentation
    {
        $name = $this->option('documentation');

        return $this->hasL5Swagger() ? Documentation::named(is_string($name) ? $name : null) : null;
    }

    protected function hasL5Swagger(): bool
    {
        return class_exists(ConfigFactory::class) && $this->laravel->bound('config') && $this->laravel->make('config')->get('l5-swagger') !== null;
    }

    protected function builder(Mode $mode, LoggerInterface $logger): Builder
    {
        // swagger-php 7.3 takes the logger in the constructor, and deprecates `setLogger()`
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists(Builder::class, 'withPlugins')) {
            return (new Builder($logger))->setMode($mode);
        }

        return (new Builder())->setMode($mode)->setLogger($logger);
    }

    /**
     * @param list<Entry> $entries
     */
    protected function summary(Inventory $inventory, array $entries, bool $listAll): void
    {
        $listed = $listAll
            ? $entries
            : array_values(array_filter($entries, static fn (Entry $entry): bool => in_array($entry->status, [Status::Unrouted, Status::Conflict], true)));

        if ($listed !== []) {
            $this->table(
                ['Method', 'Path', 'Status', 'Adapter'],
                array_map(static fn (Entry $entry): array => [
                    strtoupper($entry->method),
                    $entry->path,
                    $entry->status->value,
                    // the full class name is in the JSON; here it would make the table too wide
                    $entry->adapter !== null ? substr((string) strrchr('\\' . $entry->adapter, '\\'), 1) : '',
                ], $listed),
            );
        }

        $counts = [];
        foreach (Status::cases() as $status) {
            $counts[] = sprintf('%s: %d', $status->value, count($inventory->entries($status)));
        }

        $this->line(implode(', ', $counts));
    }

    /**
     * The statuses an option names.
     *
     * @return list<Status>
     */
    protected function statuses(string $option): array
    {
        $values = $this->option($option);

        return array_map(
            static fn (string $value): Status => Status::tryFrom($value) ?? throw new \ValueError(sprintf(
                'Invalid status "%s" for --%s. Expected one of %s.',
                $value,
                $option,
                implode(', ', array_map(static fn (Status $status): string => $status->value, Status::cases())),
            )),
            is_array($values) ? array_values(array_filter($values, is_string(...))) : [],
        );
    }
}
