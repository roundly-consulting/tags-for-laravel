<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\Tags\Support\Messages;

/**
 * Tagging a model that is not saved yet: there is no key to point the tagging at.
 */
final class UnsavedTaggableException extends TagsException
{
    public static function make(string $model): self
    {
        return new self(Messages::get('messages.unsaved_taggable', ['model' => $model]));
    }

    public function errorCode(): string
    {
        return 'unsaved_taggable';
    }

    public function status(): int
    {
        return 500;
    }
}
