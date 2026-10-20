<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Handles;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Actions\Catalog\CreateTagAction;
use RoundlyConsulting\Tags\Actions\Catalog\DeleteTagAction;
use RoundlyConsulting\Tags\Actions\Catalog\FindOrCreateTagAction;
use RoundlyConsulting\Tags\Actions\Catalog\MoveTagAction;
use RoundlyConsulting\Tags\Actions\Catalog\ReorderTagsAction;
use RoundlyConsulting\Tags\Actions\Catalog\RestoreTagAction;
use RoundlyConsulting\Tags\Actions\Catalog\UpdateTagAction;
use RoundlyConsulting\Tags\Builders\TagBuilder;
use RoundlyConsulting\Tags\Builders\TagEditor;
use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\DataTransferObjects\TagNode;
use RoundlyConsulting\Tags\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tags\Enums\TagChildren;
use RoundlyConsulting\Tags\Enums\TagOperation;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Queries\BuildTagTree;
use RoundlyConsulting\Tags\Queries\PopularTags;
use RoundlyConsulting\Tags\Queries\SuggestTags;
use RoundlyConsulting\Tags\Support\KeyShape;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagLookup;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * The catalog of one tag type, optionally within one scope: `Tags::type('genre')`.
 *
 * Reads see the scope's tags plus the global ones (`within($team)`), only the scope's
 * (`within($team, includeGlobal: false)`), or only global tags (no scope). Writes match the scope
 * exactly: a `within($team)` handle edits, moves and deletes the team's own tags and never a
 * global tag every tenant sees; an unscoped handle writes global tags only.
 */
final readonly class TypeTags
{
    public function __construct(
        private TagsManager $manager,
        private TagType $type,
        private ?Model $scope = null,
        private bool $includeGlobal = true,
    ) {}

    /**
     * The same catalog within `$scope`.
     */
    public function within(?Model $scope, bool $includeGlobal = true): self
    {
        return new self($this->manager, $this->type, $scope, $includeGlobal);
    }

    /**
     * The resolved type.
     */
    public function definition(): TagType
    {
        return $this->type;
    }

    /**
     * Start a new tag: `->make('Jazz')->under($music)->create()`.
     *
     * @param  string|array<string, string>  $name  a string is the current locale
     */
    public function make(string|array $name): TagBuilder
    {
        return new TagBuilder($this, $name);
    }

    public function create(TagData $data): Tag
    {
        return $this->manager->perform(
            TagOperation::CreateTag,
            CreateTagAction::class,
            fn (CreateTagAction $action): Tag => $action->execute($this->type, $data, $this->scope),
            ['type' => $this->type->name, 'data' => $data, 'scope' => $this->scope],
        );
    }

    /**
     * The tag the name names (in the scope, else globally), or a new one in the scope. With a
     * {@see TagData} (what `make(...)->findOrCreate()` passes), a new tag takes its parent,
     * position, colour and the rest; a found tag is returned unchanged.
     *
     * @param  string|array<string, string>|TagData  $name
     */
    public function findOrCreate(string|array|TagData $name): Tag
    {
        return $this->manager->perform(
            TagOperation::FindOrCreateTag,
            FindOrCreateTagAction::class,
            fn (FindOrCreateTagAction $action): Tag => $action->execute($this->type, $name, $this->scope),
            ['type' => $this->type->name, 'name' => $name, 'scope' => $this->scope],
        );
    }

    /**
     * Change a tag fluently: `->edit($jazz)->name('Jazz music')->save()`.
     */
    public function edit(Tag $tag): TagEditor
    {
        $this->ensureWritable($tag);

        return new TagEditor($this, $tag);
    }

    public function update(Tag $tag, UpdateTagData $data): Tag
    {
        $this->ensureWritable($tag);

        return $this->manager->perform(
            TagOperation::UpdateTag,
            UpdateTagAction::class,
            static fn (UpdateTagAction $action): Tag => $action->execute($tag, $data),
            ['type' => $this->type->name, 'tag' => $tag, 'data' => $data],
        );
    }

    /**
     * @param  string|array<string, string>  $name  a string renames the current locale
     */
    public function rename(Tag $tag, string|array $name): Tag
    {
        return $this->update($tag, new UpdateTagData(name: $name));
    }

    /**
     * Move a tag under another parent (null: a root), optionally to a position among its new
     * siblings.
     */
    public function move(Tag $tag, ?Tag $under = null, ?int $position = null): Tag
    {
        $this->ensureWritable($tag);

        if ($under !== null) {
            $this->ensureWritable($under);
        }

        return $this->manager->perform(
            TagOperation::MoveTag,
            MoveTagAction::class,
            static fn (MoveTagAction $action): Tag => $action->execute($tag, $under, $position),
            ['type' => $this->type->name, 'tag' => $tag, 'parent' => $under, 'position' => $position],
        );
    }

    /**
     * Order the siblings under `$parent` (the roots when null): the listed ones first, 0..n-1.
     *
     * @param  array<array-key, Tag|int|string>  $order  tags or their keys
     * @return Collection<int, Tag>
     */
    public function reorder(?Tag $parent, array $order): Collection
    {
        if ($parent !== null) {
            $this->ensureWritable($parent);
        }

        return $this->manager->perform(
            TagOperation::ReorderTags,
            ReorderTagsAction::class,
            fn (ReorderTagsAction $action): Collection => $action->execute($this->type, $this->scope, $parent, $order),
            ['type' => $this->type->name, 'parent' => $parent, 'order' => $order, 'scope' => $this->scope],
        );
    }

    /**
     * Soft-delete a tag and detach it everywhere. Children: refuse (default), reparent or delete.
     */
    public function delete(Tag $tag, TagChildren $children = TagChildren::Refuse): Tag
    {
        $this->ensureWritable($tag);

        return $this->manager->perform(
            TagOperation::DeleteTag,
            DeleteTagAction::class,
            static fn (DeleteTagAction $action): Tag => $action->execute($tag, $children),
            ['type' => $this->type->name, 'tag' => $tag, 'children' => $children],
        );
    }

    public function restore(Tag $tag): Tag
    {
        $this->ensureWritable($tag);

        return $this->manager->perform(
            TagOperation::RestoreTag,
            RestoreTagAction::class,
            static fn (RestoreTagAction $action): Tag => $action->execute($tag),
            ['type' => $this->type->name, 'tag' => $tag],
        );
    }

    /**
     * A live tag of this type in the readable scopes: a model, a key (int), or a name or slug
     * (string, along the locale chain, the scope's tag before a global one).
     */
    public function find(Tag|int|string $tag): ?Tag
    {
        if ($tag instanceof Tag) {
            return $this->isReadable($tag) ? $this->query()->whereKey($tag->getKey())->first() : null;
        }

        if (is_int($tag)) {
            return KeyShape::isTagKey($tag) ? $this->query()->whereKey($tag)->first() : null;
        }

        return TagLookup::byStrings($this->type, $this->readScopeKeys(), [$tag])[$tag] ?? null;
    }

    public function findOrFail(Tag|int|string $tag): Tag
    {
        return $this->find($tag) ?? throw (is_string($tag)
            ? TagNotFoundException::forName($tag, $this->type->name)
            : TagNotFoundException::generic());
    }

    /**
     * A deleted tag of this type in exactly this handle's scope — the scope a restore writes to:
     * a model, a key (int), or a name or slug (string, along the locale chain). Live tags and
     * other scopes' tags (a global one from a scoped handle included) are never returned:
     * `$labels->restore($labels->findTrashed('rock'))`.
     */
    public function findTrashed(Tag|int|string $tag): ?Tag
    {
        $query = TagModel::query()->onlyTrashed()->where('type', $this->type->name)->where('scope_key', $this->writeScopeKey());

        if ($tag instanceof Tag) {
            return $query->whereKey($tag->getKey())->first();
        }

        if (is_int($tag)) {
            return KeyShape::isTagKey($tag) ? $query->whereKey($tag)->first() : null;
        }

        return TagLookup::byStrings($this->type, [$this->writeScopeKey()], [$tag], trashed: true)[$tag] ?? null;
    }

    /**
     * Live tags of this type in the readable scopes by primary key (int, or a uuid/ulid string),
     * in the order given. Keys that are malformed, missing or of another type or scope are
     * skipped — the recipe for ids a form validated with `TagsRule::byId()`.
     *
     * @param  array<array-key, mixed>  $keys
     * @return Collection<int, Tag>
     */
    public function findMany(array $keys): Collection
    {
        $valid = [];

        foreach ($keys as $key) {
            if ((is_int($key) || is_string($key)) && KeyShape::isTagKey($key)) {
                $valid[KeyShape::canonical($key)] = $key;
            }
        }

        if ($valid === []) {
            return new Collection;
        }

        $found = $this->query()->whereKey(array_values($valid))->get()->keyBy(static fn (Tag $tag): string => KeyShape::canonical($tag->getKey()));

        return new Collection(array_values(array_filter(array_map(
            static fn (int|string $key): ?Tag => $found->get((string) $key),
            array_keys($valid),
        ))));
    }

    /**
     * Every live tag of this type in the readable scopes, by position.
     *
     * @return Collection<int, Tag>
     */
    public function all(): Collection
    {
        return $this->query()->ordered()->get();
    }

    /**
     * The roots of a tree (every tag of a flat type), by position.
     *
     * @return Collection<int, Tag>
     */
    public function roots(): Collection
    {
        return $this->query()->roots()->ordered()->get();
    }

    /**
     * The tree (or `$root`'s subtree, `$root` on top) in one query, nested by position.
     *
     * @return list<TagNode>
     */
    public function tree(?Tag $root = null): array
    {
        if ($root !== null && ! $this->isReadable($root)) {
            throw TagNotFoundException::generic();
        }

        return $this->manager->container()->make(BuildTagTree::class)->build($this->type, $this->readScopeKeys(), $root);
    }

    /**
     * Autocomplete: slug-prefix matches in every locale first, then name matches, by usage.
     *
     * @return Collection<int, Tag>
     */
    public function suggest(string $term, ?int $limit = null): Collection
    {
        return $this->manager->container()->make(SuggestTags::class)->suggest($this->type, $this->readScopeKeys(), $term, $limit);
    }

    /**
     * The most used tags; `$on` counts one taggable class live, `$where` restricts that count to
     * the taggables a consumer may show.
     *
     * @param  class-string<Model>|null  $on
     * @param  (Closure(Builder<Model>): mixed)|null  $where
     * @return Collection<int, Tag>
     */
    public function popular(?int $limit = null, ?string $on = null, ?Closure $where = null): Collection
    {
        return $this->manager->container()->make(PopularTags::class)->popular($this->type, $this->readScopeKeys(), $limit, $on, $where);
    }

    /**
     * Live tags of this type in the readable scopes.
     *
     * @return Builder<Tag>
     */
    public function query(): Builder
    {
        return TagModel::query()->where('type', $this->type->name)->whereIn('scope_key', $this->readScopeKeys());
    }

    /**
     * The scope key this handle writes to: exactly its scope ('' for global tags).
     *
     * @internal
     */
    public function writeScopeKey(): string
    {
        return ScopeKey::for($this->scope);
    }

    /**
     * The scope keys this handle reads, the scope's first.
     *
     * @internal
     *
     * @return list<string>
     */
    public function readScopeKeys(): array
    {
        if ($this->scope === null || $this->type->globalOnly) {
            return [ScopeKey::GLOBAL];
        }

        $key = ScopeKey::for($this->scope);

        return $this->includeGlobal ? [$key, ScopeKey::GLOBAL] : [$key];
    }

    /**
     * A write through this handle must target a tag of its type in exactly its scope.
     *
     * @internal
     */
    public function ensureWritable(Tag $tag): void
    {
        if ($tag->type !== $this->type->name) {
            throw TagTypeMismatchException::expected($this->type->name, $tag->type);
        }

        if ($tag->scope_key !== $this->writeScopeKey()) {
            throw TagScopeMismatchException::forWrite((string) $tag->slug);
        }
    }

    private function isReadable(Tag $tag): bool
    {
        return $tag->type === $this->type->name && in_array($tag->scope_key, $this->readScopeKeys(), true);
    }
}
