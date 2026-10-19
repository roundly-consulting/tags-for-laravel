<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags;

use BackedEnum;
use Closure;
use Illuminate\Container\Container as CurrentContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RoundlyConsulting\Tags\Actions\Internal\ClearDeletedTaggableAction;
use RoundlyConsulting\Tags\Actions\Maintenance\RepairTagsAction;
use RoundlyConsulting\Tags\Actions\Tagging\AttachTagsAction;
use RoundlyConsulting\Tags\Actions\Tagging\ClearTagsAction;
use RoundlyConsulting\Tags\Actions\Tagging\DetachTagsAction;
use RoundlyConsulting\Tags\Actions\Tagging\SyncTagsAction;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\DataTransferObjects\RepairOptions;
use RoundlyConsulting\Tags\DataTransferObjects\RepairReport;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Enums\TagOperation;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Handles\TaggableTags;
use RoundlyConsulting\Tags\Handles\TypeTags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Queries\LoadAncestors;
use RoundlyConsulting\Tags\Support\KeyShape;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeBuilder;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * The package's public API: the root behind the `Tags` facade, injectable by its own
 * class-string and bound as a singleton. Types are declared here (`define`, `extend`), the
 * catalog of one type is reached through `type()`, and a model's tags through `for()` or the flat
 * verbs. Every write funnels through {@see self::perform()}, which resolves the action from the
 * container — so a host override applies and `Tags::fake()` records it.
 *
 * Not final on purpose: `TagsFake` extends it, so an injected manager still type-checks under
 * the fake.
 *
 * Octane-safe: the manager is a singleton a consumer resolves at boot, so it holds no container —
 * actions and read services come from the current one ({@see self::container()}), so a binding a
 * request's sandbox makes applies. The type registry is an app-lifetime singleton on purpose:
 * types are defined once, at boot.
 */
class TagsManager
{
    public function __construct(
        protected readonly TagTypeRegistry $registry,
    ) {}

    /**
     * Declare a tag type, from a service provider's `boot()`. A second definition of the name
     * throws; adjust another package's type with {@see self::extend()} or `tags.overrides`.
     */
    public function define(string|BackedEnum $type): TagTypeBuilder
    {
        return $this->registry->define($type);
    }

    /**
     * Adjust a type from host code, whatever the provider order.
     *
     * @param  Closure(TagTypeBuilder): mixed  $callback
     */
    public function extend(string|BackedEnum $type, Closure $callback): void
    {
        $this->registry->extend($type, $callback);
    }

    /**
     * Every defined type, resolved, keyed by name.
     *
     * @return Collection<string, TagType>
     */
    public function types(): Collection
    {
        return new Collection($this->registry->all());
    }

    /**
     * The catalog of one type: create, find, move, suggest… Unknown names throw.
     */
    public function type(string|BackedEnum $type): TypeTags
    {
        return new TypeTags($this, $this->registry->resolve($type));
    }

    /**
     * A live tag of any type by primary key.
     */
    public function find(int|string $key): ?Tag
    {
        return KeyShape::isTagKey($key) ? TagModel::query()->whereKey($key)->first() : null;
    }

    public function findOrFail(int|string $key): Tag
    {
        return $this->find($key) ?? throw TagNotFoundException::forKey($key);
    }

    /**
     * One model's tags: `Tags::for($event)->attach(['live'], 'label')`.
     */
    public function for(Model&Taggable $taggable): TaggableTags
    {
        return new TaggableTags($this, $taggable);
    }

    /**
     * Load the ancestors of many tags in one query, so `$tag->ancestors()` and
     * `TagSummaryResource` read them without a query: `Tags::loadAncestors($events->map->category)`.
     * Nulls are skipped; anything else that is not a tag throws.
     *
     * @param  iterable<mixed>  $tags  Tag models or nulls
     */
    public function loadAncestors(iterable $tags): void
    {
        $this->container()->make(LoadAncestors::class)->load($tags);
    }

    /**
     * Add tags to a model. Unknown names of a free-form type are created in the model's scope;
     * going over a type's max throws and writes nothing.
     *
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function attach(Model&Taggable $taggable, mixed $tags, string|BackedEnum|null $type = null): TagChanges
    {
        $selection = TagSelection::of($tags, $type);

        return $this->perform(
            TagOperation::Attach,
            AttachTagsAction::class,
            static fn (AttachTagsAction $action): TagChanges => $action->execute($taggable, $selection),
            ['taggable' => $taggable, 'tags' => $selection->tags, 'type' => $selection->type],
        );
    }

    /**
     * Remove tags from a model; names that name no tag are ignored.
     *
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function detach(Model&Taggable $taggable, mixed $tags, string|BackedEnum|null $type = null): TagChanges
    {
        $selection = TagSelection::of($tags, $type);

        return $this->perform(
            TagOperation::Detach,
            DetachTagsAction::class,
            static fn (DetachTagsAction $action): TagChanges => $action->execute($taggable, $selection),
            ['taggable' => $taggable, 'tags' => $selection->tags, 'type' => $selection->type],
        );
    }

    /**
     * Make a model's tags of one type (the default type when none is given) exactly these.
     *
     * @param  mixed  $tags  Tag|int|string|iterable<Tag|int|string>|null
     */
    public function sync(Model&Taggable $taggable, mixed $tags, string|BackedEnum|null $type = null): TagChanges
    {
        $selection = TagSelection::of($tags, $type);

        return $this->perform(
            TagOperation::Sync,
            SyncTagsAction::class,
            static fn (SyncTagsAction $action): TagChanges => $action->execute($taggable, $selection),
            ['taggable' => $taggable, 'tags' => $selection->tags, 'type' => $selection->type],
        );
    }

    /**
     * Remove every tag of a type from a model, or every tag of every type.
     */
    public function clear(Model&Taggable $taggable, string|BackedEnum|null $type = null): TagChanges
    {
        return $this->perform(
            TagOperation::Clear,
            ClearTagsAction::class,
            static fn (ClearTagsAction $action): TagChanges => $action->execute($taggable, $type),
            ['taggable' => $taggable, 'type' => $type === null ? null : TagTypeRegistry::nameOf($type)],
        );
    }

    /**
     * The deletion hook's clear of a taggable that is gone for good: no saved-model guard, no
     * row to lock. Recorded as a clear.
     *
     * @internal
     */
    public function clearDeleted(Model&Taggable $taggable): TagChanges
    {
        return $this->perform(
            TagOperation::Clear,
            ClearDeletedTaggableAction::class,
            static fn (ClearDeletedTaggableAction $action): TagChanges => $action->execute($taggable),
            ['taggable' => $taggable, 'type' => null],
        );
    }

    /**
     * Repair derived data: paths and depths, scope keys, orphan taggings and usage counts.
     */
    public function repair(?RepairOptions $options = null): RepairReport
    {
        $options ??= new RepairOptions;

        return $this->perform(
            TagOperation::Repair,
            RepairTagsAction::class,
            static fn (RepairTagsAction $action): RepairReport => $action->execute($options),
            ['options' => $options],
        );
    }

    /**
     * Run one state-changing operation. Every flat verb, handle, builder and model method
     * funnels its terminal call through here, resolving the action from the container, so host
     * overrides apply and the fake records the call.
     *
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function perform(TagOperation $operation, string $action, Closure $execute, array $context = []): mixed
    {
        return $execute($this->container()->make($action));
    }

    /**
     * The container the manager resolves actions and read services from: always the current one,
     * never the one it was built with.
     *
     * @internal
     */
    public function container(): Container
    {
        return CurrentContainer::getInstance();
    }

    /**
     * The type registry the manager reads.
     *
     * @internal
     */
    public function registry(): TagTypeRegistry
    {
        return $this->registry;
    }
}
