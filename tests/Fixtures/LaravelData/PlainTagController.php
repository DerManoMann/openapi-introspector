<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures\LaravelData;

class PlainTagController
{
    public function store(TagData $tag): TagData
    {
        return $tag;
    }
}
