<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Exceptions;

use RuntimeException;

/**
 * The base of every exception tags throws for a request or a call it refuses. Consumers present
 * them through four stable methods rather than knowing each class: {@see self::errorCode()} (a
 * snake_case code, public API from 1.0), {@see self::status()} (the HTTP status for a reference in
 * a request body), {@see self::isNotFound()} (the not-found family, which answers 404 when the
 * reference was a path segment) and {@see self::clientMessage()} (the translated text a client
 * may see). Messages never name a PHP class.
 */
abstract class TagsException extends RuntimeException
{
    /**
     * The stable, snake_case error code. Renaming one is a major release.
     */
    abstract public function errorCode(): string;

    /**
     * The HTTP status for a reference in a request body: 422 or 409, or 500 for a programming
     * error.
     */
    public function status(): int
    {
        return 422;
    }

    /**
     * Whether this is a "does not exist (for you)" failure: a consumer resolving a path segment
     * answers 404 for these.
     */
    public function isNotFound(): bool
    {
        return false;
    }

    /**
     * The translated message a client may see.
     */
    public function clientMessage(): string
    {
        return $this->getMessage();
    }
}
