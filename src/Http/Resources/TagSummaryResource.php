<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * The shape to nest a tag in another resource (an event's category and labels): its key, type
 * name, name and slug in the current locale, colour, icon and depth — plus `ancestors` (root
 * first, each in this shape) when the relation is loaded (`Tags::loadAncestors()`). No usage
 * count, scope, parent or timestamps — so it needs no gate check, no relation and no query.
 *
 * @property Tag $resource
 */
final class TagSummaryResource extends JsonResource
{
    /**
     * @return array{id: mixed, type: string, name: string|null, slug: string|null, color: string|null, icon: string|null, depth: int, ancestors?: mixed}
     */
    public function toArray(Request $request): array
    {
        $tag = $this->resource;

        return [
            'id' => $tag->getKey(),
            'type' => $tag->type,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'color' => $tag->color,
            'icon' => $tag->icon,
            'depth' => $tag->depth,
            'ancestors' => $this->whenLoaded('ancestors', static fn (): array => self::collection($tag->ancestors())->resolve($request)),
        ];
    }
}
