<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Tags\Enums\TagOperation;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

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

    public function __construct(Container $container, TagTypeRegistry $registry)
    {
        parent::__construct($container, $registry);
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

    // Catalog --------------------------------------------------------------------------

    /**
     * A tag was created (`create()`, or a `findOrCreate()` that created one), optionally one the
     * callback accepts.
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
     * @param  (Closure(Tag): bool)|null  $callback
     */
    public function assertTagRestored(?Closure $callback = null): void
    {
        $this->assertCatalog($this->tagsOf(TagOperation::RestoreTag), 'restored', $callback);
    }

    public function assertNoTagRestored(): void
    {
        $this->assertNoCatalog($this->tagsOf(TagOperation::RestoreTag), 'restored');
    }

    /**
     * Siblings were reordered, optionally of one type.
     */
    public function assertTagsReordered(?string $type = null): void
    {
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

    /**
     * @return list<Tag>
     */
    private function created(): array
    {
        $tags = [];

        foreach ($this->recorded as $recorded) {
            if ($recorded->operation === TagOperation::CreateTag && $recorded->result instanceof Tag) {
                $tags[] = $recorded->result;
            }

            if ($recorded->operation === TagOperation::FindOrCreateTag && $recorded->result instanceof Tag && $recorded->result->wasRecentlyCreated) {
                $tags[] = $recorded->result;
            }
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
