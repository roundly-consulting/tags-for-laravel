<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Catalog;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\Events\TagCreated;
use RoundlyConsulting\Tags\Events\TagRestored;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\TagDrafts;
use RoundlyConsulting\Tags\Support\TagStore;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Create a tag of a type, global or in a scope. A name a live tag of the type and scope already
 * holds (in any locale) is a duplicate, never "rock-2"; a name a deleted tag holds restores that
 * tag with the new attributes. A child, or a tag at an explicit position, is created under the
 * tree lock.
 */
final readonly class CreateTagAction
{
    public function __construct(
        private TagTypeRegistry $registry,
    ) {}

    public function execute(string|BackedEnum|TagType $type, TagData $data, ?Model $scope = null): Tag
    {
        $draft = TagDrafts::fromData($this->registry->resolve($type), $data, $scope);

        return TagTransaction::run(static function () use ($draft): Tag {
            $stored = TagStore::store(TagDrafts::underLock($draft));

            event($stored->restored ? new TagRestored($stored->tag) : new TagCreated($stored->tag));

            return $stored->tag;
        });
    }
}
