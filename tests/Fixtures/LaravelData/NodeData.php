<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures\LaravelData;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class NodeData extends Data
{
    /**
     * @param list<NodeData> $children
     */
    public function __construct(
        public string $name,
        public ?NodeData $parent,
        #[DataCollectionOf(NodeData::class)]
        public array $children,
    ) {
    }
}
