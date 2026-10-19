<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tags\Http\Access;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * A tag as the API renders it. Name, slug and description are in the current locale; `type` is
 * the type name. The scope owner renders through `tags.resources.scope` (never a class name).
 * `usage_count` — which for a global tag adds up every tenant's taggings — and the full
 * `translations` (with `?include=translations`) render only to the type's managers; the check
 * runs once per type and scope per request.
 *
 * @property Tag $resource
 */
final class TagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tag = $this->resource;
        $manager = self::managedBy($request, $tag);

        return [
            'id' => $tag->getKey(),
            'type' => $tag->type,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'description' => $tag->description,
            'color' => $tag->color,
            'icon' => $tag->icon,
            'parent_id' => $tag->parent_id,
            'depth' => $tag->depth,
            'position' => $tag->position,
            'usage_count' => $this->when($manager, $tag->usage_count),
            'scope' => self::scope($tag, $request),
            'ancestors' => $this->whenLoaded('ancestors', static fn (): mixed => TagSummaryResource::collection($tag->getRelation('ancestors'))->resolve($request)),
            'translations' => $this->when($manager && $request->attributes->get('tags.include_translations') === true, static fn (): array => [
                'name' => $tag->getTranslations('name'),
                'description' => $tag->getTranslations('description'),
                'slug' => $tag->getTranslations('slug'),
            ]),
            'deleted_at' => $this->when($tag->trashed(), static fn (): ?string => $tag->deleted_at?->toIso8601String()),
            'created_at' => $tag->created_at?->toIso8601String(),
            'updated_at' => $tag->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The scope owner through the configured resource, resolved in place; null for a global tag.
     *
     * @return array<array-key, mixed>|null
     */
    public static function scope(Tag $tag, Request $request): ?array
    {
        if ($tag->scope_type === null) {
            return null;
        }

        $scope = $tag->scope;

        if (! $scope instanceof Model) {
            return null;
        }

        $resource = TagsConfig::scopeResource();

        return (new $resource($scope))->resolve($request);
    }

    private static function managedBy(Request $request, Tag $tag): bool
    {
        $type = app(TagTypeRegistry::class)->find($tag->type);
        $scope = $request->attributes->get('tags.request_scope');

        return $type !== null && Access::canManage($request, $type, $scope instanceof Model ? $scope : null);
    }
}
