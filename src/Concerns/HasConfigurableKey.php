<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Tags\Support\TagsConfig;

/**
 * Drives the tag model's primary key from `tags.primary_key_type`, matching the column the
 * migration emitted for the same value: auto-increment on bigint, uuid7 or ulid otherwise.
 * A local copy of the posts/tickets pattern.
 *
 * @internal
 */
trait HasConfigurableKey
{
    use HasUniqueStringIds;

    public function initializeHasUniqueStringIds(): void
    {
        $this->usesUniqueIds = $this->configuredKeyType() !== KeyType::BigInt;
    }

    public function newUniqueId(): string
    {
        return $this->configuredKeyType() === KeyType::Ulid
            ? (string) Str::ulid()
            : (string) Str::uuid7();
    }

    public function configuredKeyType(): KeyType
    {
        return TagsConfig::primaryKeyType();
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return $this->configuredKeyType() === KeyType::Ulid
            ? Str::isUlid($value)
            : Str::isUuid($value);
    }
}
