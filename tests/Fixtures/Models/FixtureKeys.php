<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * Host models keyed by `tags.key_type`, as the package requires of every taggable and scope.
 */
trait FixtureKeys
{
    use HasUniqueStringIds;

    public function initializeHasUniqueStringIds(): void
    {
        $this->usesUniqueIds = KeyType::fromConfig('tags.key_type') !== KeyType::BigInt;
    }

    public function newUniqueId(): string
    {
        return KeyType::fromConfig('tags.key_type') === KeyType::Ulid ? (string) Str::ulid() : (string) Str::uuid7();
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return KeyType::fromConfig('tags.key_type') === KeyType::Ulid ? Str::isUlid($value) : Str::isUuid($value);
    }
}
