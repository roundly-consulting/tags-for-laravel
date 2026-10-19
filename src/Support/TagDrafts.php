<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\DataTransferObjects\TagDraft;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * Turns a create request into a validated {@see TagDraft}.
 *
 * @internal
 */
final class TagDrafts
{
    public static function fromData(TagType $type, TagData $data, ?Model $scope): TagDraft
    {
        if ($scope !== null && $type->globalOnly) {
            throw InvalidTagException::globalOnlyType($type->name);
        }

        $scopeKey = ScopeKey::for($scope);
        $slug = TagAttributes::slug($data->slug === [] ? null : $data->slug);

        if ($slug !== [] && ! $type->curated) {
            throw InvalidTagException::manualSlugOnFreeForm($type->name);
        }

        if ($data->parent !== null) {
            self::ensureParent($type, $scopeKey, $data->parent);
        }

        return new TagDraft(
            type: $type,
            scope: $scope,
            scopeKey: $scopeKey,
            name: TagName::map($data->name),
            description: TagAttributes::description($data->description === [] ? null : $data->description),
            slug: $slug,
            color: TagAttributes::color($data->color),
            icon: TagAttributes::icon($data->icon),
            parent: $data->parent,
            position: TagAttributes::position($data->position),
        );
    }

    /**
     * A parent must be a live tag of the same hierarchical type and the same scope.
     */
    public static function ensureParent(TagType $type, string $scopeKey, Tag $parent): void
    {
        if (! $type->hierarchical) {
            throw InvalidTagHierarchyException::flatType($type->name);
        }

        if ($parent->type !== $type->name) {
            throw TagTypeMismatchException::expected($type->name, $parent->type);
        }

        if ($parent->scope_key !== $scopeKey) {
            throw TagScopeMismatchException::forWrite((string) $parent->slug);
        }

        if ($parent->trashed()) {
            throw InvalidTagHierarchyException::parentTrashed();
        }
    }

    /**
     * The same draft under a freshly read parent.
     */
    public static function withParent(TagDraft $draft, ?Tag $parent): TagDraft
    {
        return new TagDraft(
            type: $draft->type,
            scope: $draft->scope,
            scopeKey: $draft->scopeKey,
            name: $draft->name,
            description: $draft->description,
            slug: $draft->slug,
            color: $draft->color,
            icon: $draft->icon,
            parent: $parent,
            position: $draft->position,
        );
    }
}
