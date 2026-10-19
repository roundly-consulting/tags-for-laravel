<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Catalog;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\DataTransferObjects\TagDraft;
use RoundlyConsulting\Tags\Events\TagCreated;
use RoundlyConsulting\Tags\Events\TagRestored;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagLookup;
use RoundlyConsulting\Tags\Support\TagName;
use RoundlyConsulting\Tags\Support\TagStore;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * The tag a name names — in the scope, else globally — or a new one in the scope. Allowed on
 * curated types too: it is an explicit catalog call. A locale map is matched on the current
 * locale's entry (else its first); its other entries only fill a newly created tag. Two racing
 * calls end with one tag.
 */
final readonly class FindOrCreateTagAction
{
    public function __construct(
        private TagTypeRegistry $registry,
    ) {}

    /**
     * @param  string|array<string, string>  $name
     */
    public function execute(string|BackedEnum|TagType $type, string|array $name, ?Model $scope = null): Tag
    {
        $type = $this->registry->resolve($type);

        if ($scope !== null && $type->globalOnly) {
            throw InvalidTagException::globalOnlyType($type->name);
        }

        $scopeKey = ScopeKey::for($scope);
        $map = TagName::map($name);
        $lookup = TagName::lookup($map);
        $keys = $scopeKey === ScopeKey::GLOBAL ? [ScopeKey::GLOBAL] : [$scopeKey, ScopeKey::GLOBAL];

        $found = TagLookup::byStrings($type, $keys, [$lookup])[$lookup] ?? null;

        if ($found !== null) {
            return $found;
        }

        return TagTransaction::run(static function () use ($type, $scope, $scopeKey, $map, $lookup, $keys): Tag {
            // A racer may have created it (or a global one) since the unlocked lookup.
            $found = TagLookup::byStrings($type, $keys, [$lookup])[$lookup] ?? null;

            if ($found !== null) {
                return $found;
            }

            $stored = TagStore::store(new TagDraft($type, $scope, $scopeKey, $map), $lookup);

            if ($stored->created) {
                event(new TagCreated($stored->tag));
            } elseif ($stored->restored) {
                event(new TagRestored($stored->tag));
            }

            return $stored->tag;
        });
    }
}
