<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Tagging;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Actions\Internal\ApplyTagChangesAction;
use RoundlyConsulting\Tags\Actions\Internal\ResolveTagsAction;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\DataTransferObjects\ResolvedSelection;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\DataTransferObjects\TagDraft;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Events\TagCreated;
use RoundlyConsulting\Tags\Events\TagRestored;
use RoundlyConsulting\Tags\Exceptions\TagLimitExceededException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\Taggings;
use RoundlyConsulting\Tags\Support\TagName;
use RoundlyConsulting\Tags\Support\TagStore;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * Add tags to a model: the current tags plus the resolved ones. Unknown names of a free-form type
 * are created in the model's scope; a curated type never creates. Going over a type's max throws
 * and writes nothing — single types included (`syncTags()` replaces a category).
 */
final readonly class AttachTagsAction
{
    public function __construct(
        private ResolveTagsAction $resolve,
        private ApplyTagChangesAction $apply,
    ) {}

    public function execute(Model&Taggable $taggable, TagSelection $selection): TagChanges
    {
        Taggings::ensureTaggable($taggable);

        if ($selection->isEmpty()) {
            return new TagChanges($taggable);
        }

        $resolve = $this->resolve;
        $apply = $this->apply;

        return TagTransaction::run(static function () use ($taggable, $selection, $resolve, $apply): TagChanges {
            // The model's row first: every later read of this call sees what the writer before it
            // committed, and the max is checked once, before any tag is created.
            Taggings::lock($taggable);

            $resolved = $resolve->execute($taggable, $selection, strict: true);
            $current = Taggings::current($taggable, locked: Taggings::nested());

            self::ensureWithinMax($resolved->types, $current, self::adding($resolved));

            $attach = [];

            foreach (self::createMissing($taggable, $resolved) as $tag) {
                if (! isset($current[(string) $tag->getKey()])) {
                    $attach[(string) $tag->getKey()] = $tag->getKey();
                }
            }

            return $apply->execute($taggable, array_values($attach), []);
        });
    }

    /**
     * Everything the call names, as key => type: existing tags by key, names still to create by a
     * placeholder key.
     *
     * @return array<string, string>
     */
    private static function adding(ResolvedSelection $resolved): array
    {
        $adding = [];

        foreach ($resolved->tags as $type => $tags) {
            foreach ($tags as $tag) {
                $adding[(string) $tag->getKey()] = $type;
            }
        }

        foreach ($resolved->missing as $type => $names) {
            foreach (array_keys($names) as $slug) {
                $adding['new:'.$type.':'.$slug] = $type;
            }
        }

        return $adding;
    }

    /**
     * A type is over its max when the model would carry more of it than allowed — checked only
     * for types this call adds to, so a model already over a lowered max may still shrink.
     *
     * @param  array<string, TagType>  $types
     * @param  array<string, string>  $current  tag key => type
     * @param  array<string, string>  $adding  tag key => type
     */
    private static function ensureWithinMax(array $types, array $current, array $adding): void
    {
        foreach ($types as $type) {
            if ($type->max === null) {
                continue;
            }

            $carried = count(array_filter($current, static fn (string $currentType): bool => $currentType === $type->name));
            $new = count(array_filter(
                $adding,
                static fn (string $addingType, int|string $key): bool => $addingType === $type->name && ! isset($current[$key]),
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($new > 0 && $carried + $new > $type->max) {
                throw TagLimitExceededException::forType($type->name, $type->max);
            }
        }
    }

    /**
     * Every resolved tag, the free-form ones created (or restored, or found after a race) in the
     * model's scope.
     *
     * @return list<Tag>
     */
    private static function createMissing(Model&Taggable $taggable, ResolvedSelection $resolved): array
    {
        $tags = [];

        foreach ($resolved->tags as $byType) {
            foreach ($byType as $tag) {
                $tags[] = $tag;
            }
        }

        foreach ($resolved->missing as $name => $names) {
            $type = $resolved->types[$name];
            $scope = Taggings::creationScope($taggable, $type);

            foreach ($names as $map) {
                $stored = TagStore::store(new TagDraft($type, $scope, ScopeKey::for($scope), $map), TagName::lookup($map));

                if ($stored->created) {
                    event(new TagCreated($stored->tag));
                } elseif ($stored->restored) {
                    event(new TagRestored($stored->tag));
                }

                $tags[] = $stored->tag;
            }
        }

        return $tags;
    }
}
