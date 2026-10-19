<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RoundlyConsulting\PackageToolkit\Exceptions\PackageToolkitException;
use RoundlyConsulting\Tags\Support\Messages;

/**
 * Configuration tags cannot work with, named by its key. A configuration error, so it is not a
 * {@see TagsException}: hosts catch it beside the toolkit's own configuration exception.
 */
final class InvalidTagsConfigurationException extends PackageToolkitException
{
    public static function keyTypeMismatch(string $model): self
    {
        return new self(Messages::get('messages.config.key_type_mismatch', ['model' => $model]));
    }

    public static function invalid(string $key, string $expected): self
    {
        return new self(Messages::get('messages.config.invalid', ['key' => $key, 'expected' => $expected]));
    }
}
