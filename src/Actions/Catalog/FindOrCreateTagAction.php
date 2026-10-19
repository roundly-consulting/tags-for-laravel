<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Catalog;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\DataTransferObjects\TagDraft;
use RoundlyConsulting\Tags\Events\TagCreated;
use RoundlyConsulting\Tags\Events\TagRestored;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagDrafts;
use RoundlyConsulting\Tags\Support\TagLookup;
use RoundlyConsulting\Tags\Support\TagName;
use RoundlyConsulting\Tags\Support\TagStore;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * The tag a name names — in the scope, else globally — or a new one in the scope. Allowed on
 * curated types too: it is an explicit catalog call. A locale map is matched on the current
 * locale's entry (else its first); its other entries only fill a newly created tag. With a
 * {@see TagData}, the lookup is by its name exactly the same, and only a newly created tag takes
 * its other attributes (parent, position, colour, icon, description, manual slug): a tag that is
 * found is returned unchanged — never moved, recoloured or renamed — so a seeder may run again.
 * Two racing calls end with one tag.
 */
final readonly class FindOrCreateTagAction
{
    public function __construct(
        private TagTypeRegistry $registry,
    ) {}

    /**
     * @param  string|array<string, string>|TagData  $name
     */
    public function execute(string|BackedEnum|TagType $type, string|array|TagData $name, ?Model $scope = null): Tag
    {
        $type = $this->registry->resolve($type);
        $draft = $name instanceof TagData
            ? TagDrafts::fromData($type, $name, $scope)
            : self::draft($type, $name, $scope);

        $lookup = TagName::lookup($draft->name);
        $keys = $draft->scopeKey === ScopeKey::GLOBAL ? [ScopeKey::GLOBAL] : [$draft->scopeKey, ScopeKey::GLOBAL];

        $found = TagLookup::byStrings($type, $keys, [$lookup])[$lookup] ?? null;

        if ($found !== null) {
            return $found;
        }

        return TagTransaction::run(static function () use ($type, $draft, $lookup, $keys): Tag {
            $draft = TagDrafts::underLock($draft);

            // A racer may have created it (or a global one) since the unlocked lookup.
            $found = TagLookup::byStrings($type, $keys, [$lookup])[$lookup] ?? null;

            if ($found !== null) {
                return $found;
            }

            $stored = TagStore::store($draft, $lookup, locked: true);

            if ($stored->created) {
                event(new TagCreated($stored->tag));
            } elseif ($stored->restored) {
                event(new TagRestored($stored->tag));
            }

            return $stored->tag;
        });
    }

    /**
     * @param  string|array<string, string>  $name
     */
    private static function draft(TagType $type, string|array $name, ?Model $scope): TagDraft
    {
        if ($scope !== null && $type->globalOnly) {
            throw InvalidTagException::globalOnlyType($type->name);
        }

        return new TagDraft($type, $scope, ScopeKey::for($scope), TagName::map($name));
    }
}
