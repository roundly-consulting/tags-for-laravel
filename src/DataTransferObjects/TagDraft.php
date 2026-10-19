<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * A validated tag about to be stored: the normalised input of a create, a find-or-create or a
 * free-form tagging.
 *
 * @internal
 */
final readonly class TagDraft
{
    /**
     * @param  array<string, string>  $name
     * @param  array<string, string>  $description
     * @param  array<string, string>  $slug  manual slugs
     */
    public function __construct(
        public TagType $type,
        public ?Model $scope,
        public string $scopeKey,
        public array $name,
        public array $description = [],
        public array $slug = [],
        public ?string $color = null,
        public ?string $icon = null,
        public ?Tag $parent = null,
        public ?int $position = null,
    ) {}
}
