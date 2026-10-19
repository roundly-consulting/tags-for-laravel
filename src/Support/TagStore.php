<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Sluggable\Exceptions\SlugAlreadyTakenException;
use RoundlyConsulting\Tags\DataTransferObjects\StoredTag;
use RoundlyConsulting\Tags\DataTransferObjects\TagDraft;
use RoundlyConsulting\Tags\Exceptions\DuplicateTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * Stores a validated draft, inside the caller's transaction: inserts it, restores the deleted tag
 * that holds its name, or — for a find-or-create — returns the tag a racer created meanwhile.
 *
 * Every locale's slug is probed first (a trashed holder included), so a duplicate names its
 * locale. The insert runs in a savepoint; a unique violation (or sluggable's probe catching a
 * racer) re-reads the holders with a locking read, which sees the winner under MySQL's
 * REPEATABLE READ too.
 *
 * @internal
 */
final class TagStore
{
    /**
     * @param  string|null  $lookup  for a find-or-create: the string the tag is looked up by; a
     *                               live tag it names is returned instead of a duplicate error
     */
    public static function store(TagDraft $draft, ?string $lookup = null): StoredTag
    {
        $slugs = self::slugs($draft);
        $holders = TagLookup::holders($draft->type, $draft->scopeKey, $slugs, lock: true);

        if ($lookup !== null) {
            $existing = TagLookup::matchIn($holders, [$draft->scopeKey], $lookup);

            if ($existing !== null) {
                return new StoredTag($existing);
            }
        }

        $live = $holders->first(static fn (Tag $tag): bool => ! $tag->trashed());

        if ($live !== null) {
            throw self::duplicate($live, $slugs, false);
        }

        $trashed = $holders->unique(static fn (Tag $tag): string => (string) $tag->getKey())->values();

        if ($trashed->count() > 1) {
            throw self::duplicate($trashed[1], $slugs, true);
        }

        $tag = $trashed->first();

        if ($tag !== null) {
            self::fill($tag, $draft);
            $tag->restore();

            return new StoredTag($tag, restored: true);
        }

        $tag = TagModel::make();
        $tag->forceFill([
            'type' => $draft->type->name,
            'scope_type' => $draft->scope?->getMorphClass(),
            'scope_id' => $draft->scope?->getKey(),
            'scope_key' => $draft->scopeKey,
            'usage_count' => 0,
        ]);
        self::fill($tag, $draft);

        try {
            TagTransaction::savepoint(static fn (): bool => $tag->save());
        } catch (UniqueConstraintViolationException|SlugAlreadyTakenException|DuplicateTagException $exception) {
            $winners = TagLookup::holders($draft->type, $draft->scopeKey, $slugs, lock: true);
            $existing = $lookup === null ? null : TagLookup::matchIn($winners, [$draft->scopeKey], $lookup);

            if ($existing !== null) {
                return new StoredTag($existing);
            }

            $winner = $winners->first();

            throw $winner === null ? DuplicateTagException::generic() : self::duplicate($winner, $slugs, $winner->trashed());
        }

        return new StoredTag($tag, created: true);
    }

    /**
     * The slug every locale of the draft will take: the manual one, else the name's.
     *
     * @return array<string, string>
     */
    public static function slugs(TagDraft $draft): array
    {
        $slugs = [];

        foreach ($draft->name as $locale => $name) {
            $slugs[$locale] = TagSlugs::slugify($name, $locale);
        }

        return [...$slugs, ...$draft->slug];
    }

    /**
     * The next free position below `$parent` (the roots with no parent) of a type and scope, or
     * `$position` after shifting the siblings at and after it by one.
     */
    public static function position(string $type, string $scopeKey, ?Tag $parent, ?int $position, ?Tag $except = null): int
    {
        $siblings = self::siblings($type, $scopeKey, $parent, $except);

        if ($position === null) {
            $max = (clone $siblings)->max('position');

            return is_numeric($max) ? (int) $max + 1 : 0;
        }

        (clone $siblings)->where('position', '>=', $position)->increment('position');

        return $position;
    }

    /**
     * The live siblings below `$parent` (the roots with no parent) of a type and scope.
     *
     * @return Builder<Tag>
     */
    public static function siblings(string $type, string $scopeKey, ?Tag $parent, ?Tag $except = null): Builder
    {
        $query = TagModel::query()->where('type', $type)->where('scope_key', $scopeKey);

        $parent === null ? $query->whereNull('parent_id') : $query->where('parent_id', $parent->getKey());

        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }

        return $query;
    }

    private static function fill(Tag $tag, TagDraft $draft): void
    {
        $parent = $draft->parent;

        if ($parent !== null && $draft->type->maxDepth !== null && TagPath::depthUnder($parent) > $draft->type->maxDepth) {
            throw InvalidTagHierarchyException::tooDeep($draft->type->maxDepth);
        }

        $tag->forceFill([
            'parent_id' => $parent?->getKey(),
            'path' => TagPath::under($parent),
            'depth' => TagPath::depthUnder($parent),
            'position' => self::position($draft->type->name, $draft->scopeKey, $parent, $draft->position, $tag->exists ? $tag : null),
            'color' => $draft->color,
            'icon' => $draft->icon,
        ]);

        $tag->setTranslations('name', $draft->name);
        $tag->setTranslations('description', $draft->description);

        // A restored tag keeps only the slugs of the locales it is named in now, plus manual ones;
        // sluggable fills the rest from the new names.
        $kept = $tag->exists ? array_intersect_key($tag->getTranslations('slug'), $draft->name) : [];
        $tag->setTranslations('slug', [...$kept, ...$draft->slug]);
    }

    /**
     * @param  array<string, string>  $slugs
     */
    private static function duplicate(Tag $holder, array $slugs, bool $trashed): DuplicateTagException
    {
        foreach ($slugs as $locale => $slug) {
            if ($holder->slugFor($locale) === $slug) {
                return $trashed ? DuplicateTagException::heldByTrashed($slug, $locale) : DuplicateTagException::forSlug($slug, $locale);
            }
        }

        return DuplicateTagException::generic();
    }
}
