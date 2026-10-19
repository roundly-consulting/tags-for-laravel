<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Testing;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\DataTransferObjects\RepairReport;
use RoundlyConsulting\Tags\Enums\TagOperation;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagSlugs;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * The test double `Tags::fake()` installs. It extends the manager, so injected managers keep
 * type-checking, and it records every successful mutation — from the facade, dependency
 * injection, handles, builders and model traits — while each call still runs for real. A call
 * that throws records nothing.
 *
 * Assertions come in pairs: `assert<Verb>()` / `assertNothing<Verb>()` for the tagging verbs and
 * `assert<Noun><Verb>()` / `assertNo<Noun><Verb>()` for the catalog.
 */
final class TagsFake extends TagsManager
{
    /** @var list<RecordedTagOperation> */
    private array $recorded = [];

    public function __construct(TagTypeRegistry $registry)
    {
        parent::__construct($registry);
    }

    public function perform(TagOperation $operation, string $action, Closure $execute, array $context = []): mixed
    {
        $result = parent::perform($operation, $action, $execute, $context);

        $this->recorded[] = new RecordedTagOperation($operation, $context, $result);

        return $result;
    }

    /**
     * Every recorded operation, optionally of one kind, in call order.
     *
     * @return list<RecordedTagOperation>
     */
    public function recorded(?TagOperation $operation = null): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (RecordedTagOperation $recorded): bool => $operation === null || $recorded->operation === $operation,
        ));
    }

    public function assertNothingHappened(): void
    {
        PHPUnit::assertEmpty(
            $this->recorded,
            sprintf('Expected no tag operations, but %d were recorded.', count($this->recorded)),
        );
    }

    // Tagging --------------------------------------------------------------------------

    /**
     * Tags were attached to `$model` (by an attach or a sync), optionally these tags (models, or
     * slugs in the current locale) of this type.
     *
     * @param  list<Tag|string>|null  $tags
     */
    public function assertTagged(Model $model, ?array $tags = null, string|BackedEnum|null $type = null): void
    {
        $attached = $this->effects([TagOperation::Attach, TagOperation::Sync], $model, $type, attached: true);

        PHPUnit::assertNotEmpty($attached, 'Expected tags to be attached to the model, but none were.');

        if ($tags !== null) {
            foreach ($tags as $tag) {
                PHPUnit::assertTrue(
                    self::contains($attached, $tag),
                    'Expected the tag ['.self::describe($tag).'] to be attached to the model, but it was not.',
                );
            }
        }
    }

    public function assertNothingTagged(): void
    {
        $count = count($this->effects([TagOperation::Attach, TagOperation::Sync], null, null, attached: true));

        PHPUnit::assertSame(0, $count, "Expected no tags to be attached, but {$count} were.");
    }

    /**
     * Tags were detached from `$model` (by a detach, a sync or a clear).
     *
     * @param  list<Tag|string>|null  $tags
     */
    public function assertUntagged(Model $model, ?array $tags = null, string|BackedEnum|null $type = null): void
    {
        $detached = $this->effects([TagOperation::Detach, TagOperation::Sync, TagOperation::Clear], $model, $type, attached: false);

        PHPUnit::assertNotEmpty($detached, 'Expected tags to be detached from the model, but none were.');

        if ($tags !== null) {
            foreach ($tags as $tag) {
                PHPUnit::assertTrue(
                    self::contains($detached, $tag),
                    'Expected the tag ['.self::describe($tag).'] to be detached from the model, but it was not.',
                );
            }
        }
    }

    public function assertNothingUntagged(): void
    {
        $count = count($this->effects([TagOperation::Detach, TagOperation::Sync, TagOperation::Clear], null, null, attached: false));

        PHPUnit::assertSame(0, $count, "Expected no tags to be detached, but {$count} were.");
    }

    /**
     * `$model`'s tags of `$type` were synced, optionally to exactly these tags.
     *
     * @param  list<Tag|string>|null  $tags
     */
    public function assertSynced(Model $model, string|BackedEnum $type, ?array $tags = null): void
    {
        $name = TagTypeRegistry::nameOf($type);
        $default = $this->registry->defaultType()?->name;
        $syncs = array_filter(
            $this->recorded(TagOperation::Sync),
            static fn (RecordedTagOperation $recorded): bool => $recorded->concerns($model)
                && ($recorded->changesType() ?? $default) === $name,
        );

        PHPUnit::assertNotEmpty($syncs, "Expected the model's [{$name}] tags to be synced, but they were not.");

        if ($tags !== null && $model instanceof Taggable) {
            $current = array_values($this->for($model)->of($name)->all());

            PHPUnit::assertCount(count($tags), $current, "Expected the model's [{$name}] tags to be synced to ".count($tags).' tag(s), but it carries '.count($current).'.');

            foreach ($tags as $tag) {
                PHPUnit::assertTrue(self::contains($current, $tag), 'Expected the tag ['.self::describe($tag).'] after the sync, but it is not there.');
            }
        }
    }

    public function assertNothingSynced(): void
    {
        $count = count($this->recorded(TagOperation::Sync));

        PHPUnit::assertSame(0, $count, "Expected no tags to be synced, but {$count} syncs were recorded.");
    }

    /**
     * `$model`'s tags were cleared: of `$type`, or of any type when null. The deletion hook's
     * clear counts.
     */
    public function assertCleared(Model $model, string|BackedEnum|null $type = null): void
    {
        $name = $type === null ? null : TagTypeRegistry::nameOf($type);
        $clears = array_filter(
            $this->recorded(TagOperation::Clear),
            static fn (RecordedTagOperation $recorded): bool => $recorded->concerns($model)
                && ($name === null || ($recorded->context['type'] ?? null) === $name),
        );

        PHPUnit::assertNotEmpty($clears, $name === null
            ? "Expected the model's tags to be cleared, but they were not."
            : "Expected the model's [{$name}] tags to be cleared, but they were not.");
    }

    public function assertNothingCleared(): void
    {
        $count = count($this->recorded(TagOperation::Clear));

        PHPUnit::assertSame(0, $count, "Expected no tags to be cleared, but {$count} clears were recorded.");
    }

    /**
     * The tags attached (or detached) by the recorded operations of these kinds.
     *
     * @param  list<TagOperation>  $operations
     * @return list<Tag>
     */
    private function effects(array $operations, ?Model $model, string|BackedEnum|null $type, bool $attached): array
    {
        $name = $type === null ? null : TagTypeRegistry::nameOf($type);
        $tags = [];

        foreach ($this->recorded as $recorded) {
            $changes = $recorded->changes();

            if ($changes === null || ! in_array($recorded->operation, $operations, true) || ($model !== null && ! $recorded->concerns($model))) {
                continue;
            }

            if ($name !== null) {
                $changes = $changes->ofType($name);
            }

            $tags = [...$tags, ...($attached ? $changes->attached : $changes->detached)];
        }

        return $tags;
    }

    /**
     * @param  list<Tag>  $tags
     */
    private static function contains(array $tags, Tag|string $expected): bool
    {
        foreach ($tags as $tag) {
            if ($expected instanceof Tag ? $tag->is($expected) : $tag->slug === TagSlugs::slugify($expected, Translatable::currentLocale())) {
                return true;
            }
        }

        return false;
    }

    private static function describe(Tag|string $tag): string
    {
        return $tag instanceof Tag ? (string) $tag->slug : $tag;
    }

    // Catalog --------------------------------------------------------------------------

    /**
     * A tag was created (`create()`, a `findOrCreate()` that created one, or a free-form name an
     * attach or a sync created), optionally one the callback accepts. A deleted tag brought back
     * counts as restored, not created.
     *
     * @param  (Closure(Tag): bool)|null  $callback
     */
    public function assertTagCreated(?Closure $callback = null): void
    {
        $this->assertCatalog($this->created(), 'created', $callback);
    }

    public function assertNoTagCreated(): void
    {
        $this->assertNoCatalog($this->created(), 'created');
    }

    /**
     * @param  (Closure(Tag): bool)|null  $callback
     */
    public function assertTagUpdated(?Closure $callback = null): void
    {
        $this->assertCatalog($this->tagsOf(TagOperation::UpdateTag), 'updated', $callback);
    }

    public function assertNoTagUpdated(): void
    {
        $this->assertNoCatalog($this->tagsOf(TagOperation::UpdateTag), 'updated');
    }

    /**
     * @param  (Closure(Tag): bool)|null  $callback
     */
    public function assertTagMoved(?Closure $callback = null): void
    {
        $this->assertCatalog($this->tagsOf(TagOperation::MoveTag), 'moved', $callback);
    }

    public function assertNoTagMoved(): void
    {
        $this->assertNoCatalog($this->tagsOf(TagOperation::MoveTag), 'moved');
    }

    /**
     * @param  (Closure(Tag): bool)|null  $callback
     */
    public function assertTagDeleted(?Closure $callback = null): void
    {
        $this->assertCatalog($this->tagsOf(TagOperation::DeleteTag), 'deleted', $callback);
    }

    public function assertNoTagDeleted(): void
    {
        $this->assertNoCatalog($this->tagsOf(TagOperation::DeleteTag), 'deleted');
    }

    /**
     * A tag was restored (`restore()`, or a deleted tag a `create()`, `findOrCreate()`, attach or
     * sync brought back for its name), optionally one the callback accepts.
     *
     * @param  (Closure(Tag): bool)|null  $callback
     */
    public function assertTagRestored(?Closure $callback = null): void
    {
        $this->assertCatalog($this->restored(), 'restored', $callback);
    }

    public function assertNoTagRestored(): void
    {
        $this->assertNoCatalog($this->restored(), 'restored');
    }

    /**
     * Siblings were reordered, optionally of one type.
     */
    public function assertTagsReordered(string|BackedEnum|null $type = null): void
    {
        $type = $type === null ? null : TagTypeRegistry::nameOf($type);
        $matching = array_filter(
            $this->recorded(TagOperation::ReorderTags),
            static fn (RecordedTagOperation $recorded): bool => $type === null || ($recorded->context['type'] ?? null) === $type,
        );

        PHPUnit::assertNotEmpty($matching, $type === null
            ? 'Expected tags to be reordered, but none were.'
            : "Expected tags of type [{$type}] to be reordered, but none were.");
    }

    public function assertNoTagsReordered(): void
    {
        PHPUnit::assertEmpty(
            $this->recorded(TagOperation::ReorderTags),
            sprintf('Expected no tags to be reordered, but %d reorders were recorded.', count($this->recorded(TagOperation::ReorderTags))),
        );
    }

    // Maintenance ----------------------------------------------------------------------

    /**
     * A repair ran (`Tags::repair()` or `tags:repair`), optionally one whose report the callback
     * accepts.
     *
     * @param  (Closure(RepairReport): bool)|null  $callback
     */
    public function assertRepaired(?Closure $callback = null): void
    {
        $reports = [];

        foreach ($this->recorded(TagOperation::Repair) as $recorded) {
            if ($recorded->result instanceof RepairReport) {
                $reports[] = $recorded->result;
            }
        }

        PHPUnit::assertNotEmpty($reports, 'Expected tags to be repaired, but they were not.');

        if ($callback !== null) {
            PHPUnit::assertNotEmpty(
                array_filter($reports, static fn (RepairReport $report): bool => $callback($report)),
                'Expected a repair, but none matched the callback.',
            );
        }
    }

    public function assertNothingRepaired(): void
    {
        $count = count($this->recorded(TagOperation::Repair));

        PHPUnit::assertSame(0, $count, "Expected no repairs, but {$count} were recorded.");
    }

    /**
     * @return list<Tag>
     */
    private function created(): array
    {
        $tags = [];

        foreach ($this->recorded as $recorded) {
            $result = $recorded->result;

            if ($recorded->operation === TagOperation::CreateTag && $result instanceof Tag && ! $result->wasRecentlyRestored) {
                $tags[] = $result;
            }

            if ($recorded->operation === TagOperation::FindOrCreateTag && $result instanceof Tag && $result->wasRecentlyCreated) {
                $tags[] = $result;
            }

            $tags = [...$tags, ...($recorded->changes()->created ?? [])];
        }

        return $tags;
    }

    /**
     * @return list<Tag>
     */
    private function restored(): array
    {
        $tags = [];

        foreach ($this->recorded as $recorded) {
            $result = $recorded->result;

            if ($recorded->operation === TagOperation::RestoreTag && $result instanceof Tag) {
                $tags[] = $result;
            }

            if (in_array($recorded->operation, [TagOperation::CreateTag, TagOperation::FindOrCreateTag], true) && $result instanceof Tag && $result->wasRecentlyRestored) {
                $tags[] = $result;
            }

            $tags = [...$tags, ...($recorded->changes()->restored ?? [])];
        }

        return $tags;
    }

    /**
     * @return list<Tag>
     */
    private function tagsOf(TagOperation $operation): array
    {
        $tags = [];

        foreach ($this->recorded($operation) as $recorded) {
            if ($recorded->result instanceof Tag) {
                $tags[] = $recorded->result;
            }
        }

        return $tags;
    }

    /**
     * @param  list<Tag>  $tags
     * @param  (Closure(Tag): bool)|null  $callback
     */
    private function assertCatalog(array $tags, string $verb, ?Closure $callback): void
    {
        $matching = array_filter($tags, static fn (Tag $tag): bool => $callback === null || $callback($tag));

        PHPUnit::assertNotEmpty($matching, $callback === null
            ? "Expected a tag to be {$verb}, but none was."
            : "Expected a tag to be {$verb}, but none matched the callback.");
    }

    /**
     * @param  list<Tag>  $tags
     */
    private function assertNoCatalog(array $tags, string $verb): void
    {
        PHPUnit::assertEmpty($tags, sprintf('Expected no tag to be %s, but %d were.', $verb, count($tags)));
    }
}
