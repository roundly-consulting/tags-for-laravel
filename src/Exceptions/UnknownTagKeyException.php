<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\DataTransferObjects\TagKey;
use RoundlyConsulting\Tags\Support\Messages;

/**
 * A tagging call named a tag by primary key (an int, or a {@see TagKey})
 * and no tag it may use holds that key. Nothing was written and no tag was created.
 *
 * A {@see TagNotFoundException}, so an existing `catch` keeps catching it, with the same
 * `tag_not_found` code and the same generic client message: a missing key never reads
 * differently from someone else's. {@see self::getMessage()} and {@see self::$key} keep the key
 * for logs.
 */
final class UnknownTagKeyException extends TagNotFoundException
{
    public function __construct(
        string $message,
        public readonly int|string $key,
    ) {
        parent::__construct($message);
    }

    public static function make(int|string $key): self
    {
        return new self(Messages::get('messages.tag_not_found_key', ['key' => (string) $key]), $key);
    }

    public function clientMessage(): string
    {
        return TagNotFoundException::generic()->getMessage();
    }
}
