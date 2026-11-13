<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures\LaravelData;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class TagData extends Data
{
    public function __construct(
        #[Max(20)]
        public string $label,
    ) {
    }
}
