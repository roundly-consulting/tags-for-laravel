<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use InvalidArgumentException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagKeyException;
use RoundlyConsulting\Tags\Support\KeyShape;
use Stringable;

/**
 * A tag named by its primary key, never by a name: `TagKey::of($id)`, or a list with
 * `Tags::keys($ids)`. Accepted wherever a tag list is (attach, detach, sync, the reads, the query
 * scopes, the catalog's find), and on any key type — an int, a numeric string, a uuid or a ulid.
 *
 * It only ever resolves an existing tag: a write given a key that names no tag it may use throws
 * {@see UnknownTagKeyException} and creates nothing, a detach
 * skips it and a query scope matches nothing. A malformed key is an unknown one.
 */
final readonly class TagKey implements Stringable
{
    public function __construct(
        public int|string $key,
    ) {}

    public static function of(int|string $key): self
    {
        return new self($key);
    }

    /**
     * One key per entry, in order: ints, strings, or keys already wrapped.
     *
     * @param  int|string|self|iterable<mixed>  $keys
     * @return list<self>
     */
    public static function many(int|string|self|iterable $keys): array
    {
        if (! is_iterable($keys)) {
            $keys = [$keys];
        }

        $list = [];

        foreach ($keys as $key) {
            $list[] = match (true) {
                $key instanceof self => $key,
                is_int($key), is_string($key) => new self($key),
                default => throw new InvalidArgumentException('Tag keys are ints or strings.'),
            };
        }

        return $list;
    }

    /**
     * Whether the key fits `tags.primary_key_type`.
     */
    public function isWellFormed(): bool
    {
        return KeyShape::isTagKey($this->key);
    }

    /**
     * The key spelled the way the database matches it (see {@see KeyShape::canonical()}).
     */
    public function canonical(): string
    {
        return KeyShape::canonical($this->key);
    }

    public function __toString(): string
    {
        return (string) $this->key;
    }
}
