<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * A tag type as the API renders it, its label translated at render time.
 *
 * @property TagType $resource
 */
final class TagTypeResource extends JsonResource
{
    /**
     * @return array{name: string, label: string, hierarchical: bool, max_depth: int|null, max: int|null, curated: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'label' => $this->resource->label(),
            'hierarchical' => $this->resource->hierarchical,
            'max_depth' => $this->resource->maxDepth,
            'max' => $this->resource->max,
            'curated' => $this->resource->curated,
        ];
    }
}
