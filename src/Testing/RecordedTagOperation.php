<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
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

    /**
     * What a tagging operation changed, or null for a catalog operation.
     */
    public function changes(): ?TagChanges
    {
        return $this->result instanceof TagChanges ? $this->result : null;
    }

    /**
     * Whether the operation tagged or untagged `$model`.
     */
    public function concerns(Model $model): bool
    {
        $taggable = $this->context['taggable'] ?? null;

        return $taggable instanceof Model && $taggable->is($model);
    }

    /**
     * The type a tagging operation named, if it named one.
     */
    public function changesType(): ?string
    {
        $type = $this->context['type'] ?? null;

        return is_string($type) ? $type : null;
    }
}
