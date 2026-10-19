<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Testing;

use RoundlyConsulting\Tags\Enums\TagOperation;

/**
 * One operation the fake recorded: what ran, with which arguments, and what it returned.
 */
final readonly class RecordedTagOperation
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public TagOperation $operation,
        public array $context,
        public mixed $result,
    ) {}
}
