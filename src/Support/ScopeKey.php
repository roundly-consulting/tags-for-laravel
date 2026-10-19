<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The internal, NOT NULL `scope_key`: '' for a global tag, "{morphClass}#{key}" for a scoped one.
 * It exists so a unique index binds global tags too (NULLs never collide in an index). It never
 * leaves the server.
 *
 * @internal
 */
final class ScopeKey
{
    public const GLOBAL = '';

    public const MAX_LENGTH = 300;

    public static function for(?Model $scope): string
    {
        if ($scope === null) {
            return self::GLOBAL;
        }

        if (! $scope->exists) {
            throw new InvalidArgumentException('A tag scope must be a saved model.');
        }

        KeyShape::ensureOutbound($scope);

        return self::fromColumns($scope->getMorphClass(), $scope->getKey());
    }

    public static function fromColumns(?string $type, mixed $id): string
    {
        if ($type === null || $type === '' || $id === null || $id === '') {
            return self::GLOBAL;
        }

        $key = $type.'#'.(is_scalar($id) ? (string) $id : '');

        if (mb_strlen($key) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                'A tag scope reference is longer than '.self::MAX_LENGTH.' characters: register a shorter morph alias for the scope model.',
            );
        }

        return $key;
    }
}
