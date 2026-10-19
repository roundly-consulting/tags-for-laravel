<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

/**
 * One tagging of a scoped tag, as `tags:doctor` reads it to check the model's current scope.
 *
 * @internal
 */
final readonly class ScopedTagging
{
    public function __construct(
        public string $taggableType,
        public string $taggableId,
        public string $scopeKey,
        public string $type,
    ) {}
}
