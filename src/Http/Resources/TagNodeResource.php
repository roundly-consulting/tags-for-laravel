<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tags\DataTransferObjects\TagNode;

/**
 * One node of a tree: the tag's summary, its parent and position, and its children.
 *
 * @property TagNode $resource
 */
final class TagNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tag = $this->resource->tag;

        return [
            ...(new TagSummaryResource($tag))->resolve($request),
            'parent_id' => $tag->parent_id,
            'position' => $tag->position,
            'children' => array_map(
                static fn (TagNode $child): array => (new self($child))->resolve($request),
                $this->resource->children,
            ),
        ];
    }
}
