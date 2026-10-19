<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Catalog;

use BackedEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Events\TagsReordered;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagStore;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Support\TreeLock;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Put the siblings under `$parent` (the roots, or every tag of a flat type, when null) in the
 * given order: positions 0..n-1 for the listed tags, then the unlisted ones after them in their
 * previous order. A listed tag that is not a live sibling in this type and scope is not found.
 */
final readonly class ReorderTagsAction
{
    public function __construct(
        private TagTypeRegistry $registry,
    ) {}

    /**
     * @param  array<array-key, Tag|int|string>  $order  tags or their keys
     * @return Collection<int, Tag> the siblings in their new order
     */
    public function execute(string|BackedEnum|TagType $type, ?Model $scope, ?Tag $parent, array $order): Collection
    {
        $type = $this->registry->resolve($type);
        $scopeKey = ScopeKey::for($scope);

        return TagTransaction::run(static function () use ($type, $scopeKey, $parent, $order): Collection {
            TreeLock::lock($type->name, $scopeKey);

            if ($parent !== null) {
                $parent = TreeLock::read(TagModel::query()->whereKey($parent->getKey())->where('type', $type->name)->where('scope_key', $scopeKey))->first()
                    ?? throw TagNotFoundException::generic();
            }

            $siblings = TreeLock::read(TagStore::siblings($type->name, $scopeKey, $parent)->ordered())->get()
                ->keyBy(static fn (Tag $tag): string => (string) $tag->getKey());

            $listed = [];

            foreach ($order as $item) {
                $key = (string) ($item instanceof Tag ? $item->getKey() : $item);

                $listed[$key] = $siblings->get($key) ?? throw TagNotFoundException::generic();
            }

            $ordered = [...array_values($listed), ...$siblings->except(array_map(strval(...), array_keys($listed)))->values()->all()];

            foreach ($ordered as $position => $tag) {
                if ($tag->position !== $position) {
                    TagModel::query()->whereKey($tag->getKey())->toBase()->update(['position' => $position]);
                    $tag->position = $position;
                    $tag->syncOriginalAttribute('position');
                }
            }

            event(new TagsReordered($type->name, $parent, $ordered));

            return new Collection($ordered);
        });
    }
}
