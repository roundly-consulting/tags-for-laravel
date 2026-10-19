<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Tags\Exceptions\InvalidTagsConfigurationException;

/**
 * Whether a key fits a key type, checked before any SQL: PostgreSQL answers a uuid in a bigint
 * column with an error and MySQL casts it silently, so a mismatch fails here naming the config
 * key instead.
 *
 * @internal
 */
final class KeyShape
{
    public static function fits(mixed $key, KeyType $type): bool
    {
        return match ($type) {
            KeyType::BigInt => is_int($key) || (is_string($key) && preg_match('/^[0-9]{1,19}$/D', $key) === 1),
            KeyType::Uuid => is_string($key) && Str::isUuid($key),
            KeyType::Ulid => is_string($key) && Str::isUlid($key),
        };
    }

    /**
     * A taggable's or scope's key must fit `tags.key_type`.
     */
    public static function ensureOutbound(Model $model): void
    {
        if (! self::fits($model->getKey(), TagsConfig::keyType())) {
            throw InvalidTagsConfigurationException::keyTypeMismatch(class_basename($model));
        }
    }

    /**
     * A tag key handed in from outside fits `tags.primary_key_type`.
     */
    public static function isTagKey(mixed $key): bool
    {
        return self::fits($key, TagsConfig::primaryKeyType());
    }
}
