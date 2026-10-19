<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * The shape to nest a tag in another resource (an event's category and labels): its key, type
 * name, name and slug in the current locale, colour, icon and depth. No usage count, scope,
 * parent or timestamps — so it needs no gate check, no relation and no query.
 *
 * @property Tag $resource
 */
final class TagSummaryResource extends JsonResource
{
    /**
     * @return array{id: mixed, type: string, name: string|null, slug: string|null, color: string|null, icon: string|null, depth: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'type' => $this->resource->type,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'color' => $this->resource->color,
            'icon' => $this->resource->icon,
            'depth' => $this->resource->depth,
        ];
    }
}
