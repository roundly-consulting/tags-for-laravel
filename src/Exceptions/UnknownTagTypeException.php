<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * A type name nobody defined (or one hidden from the caller), or a string input that named no
 * type while there is no default type.
 */
final class UnknownTagTypeException extends TagsException
{
    private bool $required = false;

    public static function named(string $type): self
    {
        // Only a well-formed name is echoed back: anything else may carry markup or a class name.
        return TagTypeRegistry::isValidName($type)
            ? new self(Messages::get('messages.unknown_type', ['type' => $type]))
            : new self(Messages::get('messages.unknown_type_unnamed'));
    }

    public static function typeRequired(): self
    {
        $exception = new self(Messages::get('messages.type_required'));
        $exception->required = true;

        return $exception;
    }

    public function errorCode(): string
    {
        return $this->required ? 'type_required' : 'unknown_tag_type';
    }

    public function isNotFound(): bool
    {
        return ! $this->required;
    }
}
