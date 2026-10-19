<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Tagging;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Actions\Internal\ApplyTagChangesAction;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\Support\Taggings;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Remove every tag of one type from a model, or every tag of every type.
 */
final readonly class ClearTagsAction
{
    public function __construct(
        private TagTypeRegistry $registry,
        private ApplyTagChangesAction $apply,
    ) {}

    public function execute(Model&Taggable $taggable, string|BackedEnum|TagType|null $type = null): TagChanges
    {
        Taggings::ensureTaggable($taggable);

        $name = $type === null ? null : $this->registry->resolve($type)->name;
        $apply = $this->apply;

        return TagTransaction::run(static function () use ($taggable, $name, $apply): TagChanges {
            Taggings::lock($taggable);

            return $apply->execute($taggable, [], array_keys(Taggings::current($taggable, $name, locked: Taggings::nested())));
        });
    }
}
