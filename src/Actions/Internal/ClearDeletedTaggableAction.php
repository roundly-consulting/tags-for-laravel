<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Internal;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\Support\Taggings;
use RoundlyConsulting\Tags\Support\TagTransaction;

/**
 * The deletion hook's clear: a taggable that is gone for good (force-deleted, or deleted without
 * soft deletes) loses every tagging, so usage counts drop and `Untagged` fires. Its row no longer
 * exists, so there is no saved-model guard and no row to lock.
 *
 * @internal
 */
final readonly class ClearDeletedTaggableAction
{
    public function __construct(
        private ApplyTagChangesAction $apply,
    ) {}

    public function execute(Model $taggable): TagChanges
    {
        $apply = $this->apply;

        return TagTransaction::run(static fn (): TagChanges => $apply->execute(
            $taggable,
            [],
            array_map(strval(...), array_keys(Taggings::current($taggable, includeDeletedTags: true))),
        ));
    }
}
