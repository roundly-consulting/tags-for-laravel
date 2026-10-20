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
            KeyType::BigInt => is_int($key) || (is_string($key) && self::isBigInt($key)),
            KeyType::Uuid => is_string($key) && Str::isUuid($key),
            KeyType::Ulid => is_string($key) && Str::isUlid($key),
        };
    }

    /**
     * Digits within the signed 64-bit range: 19 digits pass the pattern up to
     * 9999999999999999999, past what PHP and a bigint column hold.
     */
    private static function isBigInt(string $key): bool
    {
        if (preg_match('/^[0-9]{1,19}$/D', $key) !== 1) {
            return false;
        }

        return strlen($key) < 19 || strcmp($key, (string) PHP_INT_MAX) <= 0;
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

    /**
     * One spelling per tag key, to compare keys in PHP the way the database matches them: a bigint
     * without leading zeros, a uuid in lower case, a ulid in upper case. A key that does not fit
     * `tags.primary_key_type` comes back as given.
     */
    public static function canonical(mixed $key): string
    {
        $type = TagsConfig::primaryKeyType();

        if (! is_int($key) && ! is_string($key)) {
            return is_scalar($key) ? (string) $key : '';
        }

        if (is_int($key) || ! self::fits($key, $type)) {
            return (string) $key;
        }

        return match ($type) {
            KeyType::BigInt => ltrim($key, '0') === '' ? '0' : ltrim($key, '0'),
            KeyType::Uuid => strtolower($key),
            KeyType::Ulid => strtoupper($key),
        };
    }
}
