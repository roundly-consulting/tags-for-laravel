<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A tag reference (a key, a slug, a name) that matches no live tag the caller may use.
 *
 * A missing key presents to clients exactly like a tag of another scope
 * ({@see TagScopeMismatchException}): the generic {@see self::clientMessage()}, so a response
 * never tells "no such key" from "someone else's key". {@see self::getMessage()} keeps the key for
 * logs. A name only ever resolves in the caller's own scopes, and a deleted tag reaches this
 * point only when it is the caller's own, so those two stay precise.
 */
final class TagNotFoundException extends TagsException
{
    private bool $generic = false;

    public static function forKey(int|string $key): self
    {
        $exception = new self(Messages::get('messages.tag_not_found_key', ['key' => (string) $key]));
        $exception->generic = true;

        return $exception;
    }

    public static function forName(string $name, string $type): self
    {
        return new self(Messages::get('messages.tag_not_found_name', ['name' => $name, 'type' => $type]));
    }

    public static function trashed(): self
    {
        return new self(Messages::get('messages.tag_trashed'));
    }

    public static function generic(): self
    {
        return new self(Messages::get('messages.tag_not_found'));
    }

    public function errorCode(): string
    {
        return 'tag_not_found';
    }

    public function isNotFound(): bool
    {
        return true;
    }

    public function clientMessage(): string
    {
        return $this->generic ? self::generic()->getMessage() : $this->getMessage();
    }
}
