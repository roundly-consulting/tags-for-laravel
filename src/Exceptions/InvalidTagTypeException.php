<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A type definition that cannot work: a malformed name, a rule that contradicts another, a bad
 * rule value, or a builder changed after its type was resolved. Thrown at boot or at the first
 * use of the type.
 */
final class InvalidTagTypeException extends TagsException
{
    public static function invalidName(): self
    {
        return new self(Messages::get('messages.invalid_type.name'));
    }

    public static function frozen(string $type): self
    {
        return new self(Messages::get('messages.invalid_type.frozen', ['type' => $type]));
    }

    public static function rule(string $type, string $rule): self
    {
        return new self(Messages::get('messages.invalid_type.rule', ['type' => $type, 'rule' => $rule]));
    }

    public function errorCode(): string
    {
        return 'invalid_tag_type';
    }

    public function status(): int
    {
        return 500;
    }
}
