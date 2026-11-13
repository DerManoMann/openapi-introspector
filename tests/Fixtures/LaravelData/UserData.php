<?php declare(strict_types=1);

namespace Radebatz\OpenApi\Introspector\Tests\Fixtures\LaravelData;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class UserData extends Data
{
    /**
     * @param list<TagData> $tags
     */
    public function __construct(
        #[Max(50)]
        public string $firstName,
        #[Email]
        public ?string $email,
        #[Min(18)]
        public int $age,
        #[In(['admin', 'editor'])]
        public string $role,
        #[DataCollectionOf(TagData::class)]
        public array $tags,
        public TagData $mainTag,
        #[Exists('teams', 'id')]
        public int $teamId,
        #[Hidden]
        public string $password,
    ) {
    }
}
