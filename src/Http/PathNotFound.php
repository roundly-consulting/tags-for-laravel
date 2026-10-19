<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http;

use RoundlyConsulting\Tags\Exceptions\TagsException;
use RuntimeException;

/**
 * A `{type}` or `{tag}` path segment that names nothing the caller may see: answered 404 with the
 * wrapped exception's code and client message.
 *
 * @internal
 */
final class PathNotFound extends RuntimeException
{
    public function __construct(
        public readonly TagsException $reason,
    ) {
        parent::__construct($reason->getMessage(), 0, $reason);
    }
}
