<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A tag of another scope (tenant): put on a model it cannot belong to, or written through a
 * handle of another scope. It presents to clients exactly like a missing tag
 * ({@see self::errorCode()} and {@see self::clientMessage()}), so no response reveals that a tag
 * exists in someone else's scope; {@see self::getMessage()} keeps the precise text for logs.
 */
final class TagScopeMismatchException extends TagsException
{
    public static function forTag(string $slug): self
    {
        return new self(Messages::get('messages.scope_mismatch', ['tag' => $slug]));
    }

    public static function forWrite(string $slug): self
    {
        return new self(Messages::get('messages.scope_mismatch_write', ['tag' => $slug]));
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
        return TagNotFoundException::generic()->getMessage();
    }
}
