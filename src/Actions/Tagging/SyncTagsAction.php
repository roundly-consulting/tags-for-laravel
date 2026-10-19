<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Tagging;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Actions\Internal\ApplyTagChangesAction;
use RoundlyConsulting\Tags\Actions\Internal\ResolveTagsAction;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\DataTransferObjects\TagDraft;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Events\TagCreated;
use RoundlyConsulting\Tags\Events\TagRestored;
use RoundlyConsulting\Tags\Exceptions\TagLimitExceededException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagTypeException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\Taggings;
use RoundlyConsulting\Tags\Support\TagName;
use RoundlyConsulting\Tags\Support\TagStore;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Make a model's tags of one type exactly the resolved set: detach the rest, attach what is
 * missing. Tags of other types are untouched. On a single type it sets the category;
 * `sync(null)` or `sync([])` clears the type. More tags than the type's max throw.
 */
final readonly class SyncTagsAction
{
    public function __construct(
        private TagTypeRegistry $registry,
        private ResolveTagsAction $resolve,
        private ApplyTagChangesAction $apply,
    ) {}

    public function execute(Model&Taggable $taggable, TagSelection $selection): TagChanges
    {
        Taggings::ensureTaggable($taggable);

        $type = $selection->type === null
            ? ($this->registry->defaultType() ?? throw UnknownTagTypeException::typeRequired())
            : $this->registry->resolve($selection->type);

        $selection = new TagSelection($selection->tags, $type->name);
        $resolve = $this->resolve;
        $apply = $this->apply;

        $names = Taggings::typeOfNames($selection, $this->registry);

        return TagTransaction::run(static function () use ($taggable, $selection, $type, $resolve, $apply, $names): TagChanges {
            // A name may bring a tag of a tree back: its tree lock comes before the model's row.
            Taggings::lockTreeForNames($taggable, $names);
            Taggings::lock($taggable);

            $resolved = $resolve->execute($taggable, $selection, strict: true);
            $tags = $resolved->tags[$type->name] ?? [];
            $missing = $resolved->missing[$type->name] ?? [];

            if ($type->max !== null && count($tags) + count($missing) > $type->max) {
                throw TagLimitExceededException::forType($type->name, $type->max);
            }

            $scope = Taggings::creationScope($taggable, $type);
            $created = [];

            foreach ($missing as $map) {
                $stored = TagStore::store(new TagDraft($type, $scope, ScopeKey::for($scope), $map, orphanAsRoot: true), TagName::lookup($map));

                if ($stored->created) {
                    event(new TagCreated($stored->tag));
                } elseif ($stored->restored) {
                    event(new TagRestored($stored->tag));
                }

                $tags[] = $stored->tag;
                $created[] = $stored;
            }

            $current = Taggings::currentForWrite($taggable, $type->name, array_map(static fn (Tag $tag): int|string => $tag->getKey(), $tags));

            // Never more than the max checked above: the stored names only ever collapse onto
            // tags already counted.
            $desired = [];

            foreach ($tags as $tag) {
                $desired[(string) $tag->getKey()] = $tag->getKey();
            }

            return $apply->execute(
                $taggable,
                array_values(array_diff_key($desired, $current)),
                array_keys(array_diff_key($current, $desired)),
                $created,
            );
        });
    }
}
