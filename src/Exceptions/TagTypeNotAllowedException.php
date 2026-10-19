<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * A type the model may not carry: the type's `on(...)` list leaves the model's class out, or the
 * model's `tagTypes()` leaves the type out.
 */
final class TagTypeNotAllowedException extends TagsException
{
    public static function onModel(string $type, string $model): self
    {
        return new self(Messages::get('messages.type_not_allowed', ['type' => $type, 'model' => $model]));
    }

    public function errorCode(): string
    {
        return 'tag_type_not_allowed';
    }
}
