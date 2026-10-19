<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Sluggable\Exceptions\SlugAlreadyTakenException;
use RoundlyConsulting\Tags\Actions\Internal\RewriteSubtreePathsAction;
use RoundlyConsulting\Tags\DataTransferObjects\StoredTag;
use RoundlyConsulting\Tags\DataTransferObjects\TagDraft;
use RoundlyConsulting\Tags\Exceptions\DuplicateTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagHierarchyException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * Stores a validated draft, inside the caller's transaction: inserts it, restores the deleted tag
 * that holds its name, or — for a find-or-create — returns the tag a racer created meanwhile. A
 * tag of a tree is stored under the tree lock.
 *
 * Every locale's slug is probed first (a trashed holder included), so a duplicate names its
 * locale. The insert runs in a savepoint; a unique violation (or sluggable's probe catching a
 * racer) re-reads the holders with a locking read, which sees the winner under MySQL's
 * REPEATABLE READ too.
 *
 * A deleted holder comes back as it was — attributes, names and place in the tree — unless the
 * draft `replaces` it (a create) or was `placed` under a parent; see {@see self::restore()}.
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
        if ($draft->type->hierarchical) {
            self::lockTree($draft);
        }

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
            if ($draft->replaces) {
                self::fillAttributes($tag, $draft);
            }

            self::restore(
                $tag,
                $draft->type,
                $draft->placed ? $draft->parent : new NotGiven,
                $draft->replaces ? $draft->position : null,
                $draft->orphanAsRoot,
            );

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
     * Take the tree lock a draft is stored under, then lock — in one ascending sweep — the rows the
     * store may change: the draft's parent, the siblings an explicit position shifts, and a deleted
     * tag the name would bring back with its parent and subtree.
     */
    public static function lockTree(TagDraft $draft): void
    {
        TreeLock::lock($draft->type->name, $draft->scopeKey);

        $holder = TagLookup::holders($draft->type, $draft->scopeKey, self::slugs($draft))
            ->first(static fn (Tag $tag): bool => $tag->trashed());
        $keys = [$draft->parent?->getKey()];
        $sets = [];

        if ($holder !== null) {
            $keys = [...$keys, $holder->getKey(), $holder->parent_id];
            $sets[] = TagModel::query()->withTrashed()->descendantsOf($holder);
        }

        if ($draft->position !== null) {
            $under = $holder === null || $draft->placed ? $draft->parent?->getKey() : $holder->parent_id;
            $siblings = TagModel::query()->where('type', $draft->type->name)->where('scope_key', $draft->scopeKey)->where('position', '>=', $draft->position);
            $sets[] = $under === null ? $siblings->whereNull('parent_id') : $siblings->where('parent_id', $under);
        }

        TreeLock::rows($keys, ...$sets);
    }

    /**
     * Bring a deleted tag back, inside the caller's transaction and — for a tree — its tree lock.
     * Its leftover taggings are removed (a plain `$tag->delete()` detached nothing), so it never
     * relabels old models. It goes under `$parent` — {@see NotGiven}: its own parent, wherever
     * that is now (gone: refused, or a root with `$orphanAsRoot`) — at `$position`, or where it
     * was when it stays with the same parent, else last. Its depth and its live subtree's must fit
     * the type's cap, and its subtree's paths follow it.
     */
    public static function restore(Tag $tag, TagType $type, NotGiven|Tag|null $parent = new NotGiven, ?int $position = null, bool $orphanAsRoot = false): Tag
    {
        $oldPrefix = TagPath::subtreePrefix($tag);
        $oldDepth = $tag->depth;
        $oldParent = $tag->parent_id === null ? null : (string) $tag->parent_id;

        if ($parent instanceof NotGiven) {
            $parent = $oldParent === null ? null : TreeLock::read(TagModel::query()->withTrashed()->whereKey($oldParent))->first();

            if ($oldParent !== null && ($parent === null || $parent->trashed())) {
                $parent = $orphanAsRoot ? null : throw InvalidTagHierarchyException::parentTrashed();
            }
        } elseif ($parent !== null && TagPath::isWithin($parent, $tag)) {
            // A plain `$tag->delete()` leaves live children: never under one of them.
            throw InvalidTagHierarchyException::cycle();
        }

        if ($type->maxDepth !== null) {
            $deepest = TreeLock::max(TagModel::query()->descendantsOf($tag), 'depth');
            $height = $deepest === null ? 0 : $deepest - $oldDepth;

            if (TagPath::depthUnder($parent) + $height > $type->maxDepth) {
                throw InvalidTagHierarchyException::tooDeep($type->maxDepth);
            }
        }

        $tag->forceFill([
            'parent_id' => $parent?->getKey(),
            'path' => TagPath::under($parent),
            'depth' => TagPath::depthUnder($parent),
        ]);

        if ($position !== null || ($parent === null ? null : (string) $parent->getKey()) !== $oldParent) {
            $tag->position = self::position($type->name, $tag->scope_key, $parent, $position, $tag);
        }

        Taggings::purge($tag);
        $tag->restore();
        $tag->wasRecentlyRestored = true;

        app(RewriteSubtreePathsAction::class)->execute($tag, $oldPrefix, $tag->depth - $oldDepth);

        return $tag;
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

        // A plain read, even in a host transaction: a stale last position only ties two siblings
        // (they order by position, then key), and locking the siblings — of a flat type, every tag
        // of its scope — would wait on any tag a concurrent delete holds.
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

    /**
     * A new tag: placed under the draft's parent, with every attribute of the draft.
     */
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
            'position' => self::position($draft->type->name, $draft->scopeKey, $parent, $draft->position),
        ]);

        self::fillAttributes($tag, $draft);
    }

    /**
     * The draft's names, description, manual slugs, colour and icon — for a new tag, or a deleted
     * one a create brings back.
     */
    private static function fillAttributes(Tag $tag, TagDraft $draft): void
    {
        $tag->forceFill(['color' => $draft->color, 'icon' => $draft->icon]);
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
