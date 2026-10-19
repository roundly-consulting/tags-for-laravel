<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Tagging;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Actions\Internal\ApplyTagChangesAction;
use RoundlyConsulting\Tags\Actions\Internal\ResolveTagsAction;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Support\Taggings;
use RoundlyConsulting\Tags\Support\TagTransaction;

/**
 * Remove tags from a model. Resolves without creating: names that name no tag are ignored, so a
 * detach is idempotent.
 */
final readonly class DetachTagsAction
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
            Taggings::lock($taggable);

            $resolved = $resolve->execute($taggable, $selection, strict: false);
            $current = Taggings::current($taggable, locked: Taggings::nested());
            $detach = [];

            foreach ($resolved->tags as $tags) {
                foreach ($tags as $tag) {
                    if (isset($current[(string) $tag->getKey()])) {
                        $detach[] = $tag->getKey();
                    }
                }
            }

            return $apply->execute($taggable, [], $detach);
        });
    }
}
