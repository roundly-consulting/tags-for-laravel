<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Internal;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Contracts\Taggable;
use RoundlyConsulting\Tags\DataTransferObjects\ResolvedSelection;
use RoundlyConsulting\Tags\DataTransferObjects\TagSelection;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeNotAllowedException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagTypeException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\KeyShape;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\Taggings;
use RoundlyConsulting\Tags\Support\TagLookup;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagName;
use RoundlyConsulting\Tags\Support\TagSlugs;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * Resolve a tagging call's input for one model, in a fixed order so the same call always fails
 * the same way. For tag and key inputs, visibility in the model's scopes comes first: a tag of
 * another scope answers exactly like a missing key, so no later check (deleted, type, allowed
 * types) confirms that it exists somewhere else. Then the input's types (a mismatch or an unknown
 * type), the types the model may carry, then the strings — a missing name of a curated type is not
 * found, a missing name of a free-form type is collected for creation. Nothing is written.
 *
 * Strict (attach, sync) refuses what a write cannot use; lenient (detach) skips it.
 *
 * @internal
 */
final readonly class ResolveTagsAction
{
    public function __construct(
        private TagTypeRegistry $registry,
    ) {}

    public function execute(Model&Taggable $taggable, TagSelection $selection, bool $strict): ResolvedSelection
    {
        $explicit = $selection->type === null ? null : $this->registry->resolve($selection->type);

        /** @var array<string, TagType> $types */
        $types = [];
        /** @var array<string, array<string, Tag>> $tags */
        $tags = [];
        $strings = [];

        $models = [];
        $keys = [];

        foreach ($selection->tags as $input) {
            if ($input instanceof Tag) {
                $models[] = $input;
            } elseif (is_int($input)) {
                $keys[] = $input;
            } else {
                $strings[] = $input;
            }
        }

        // Input types: keys are loaded to learn theirs.
        foreach ($this->loadKeys($keys, $strict) as $tag) {
            $models[] = $tag;
        }

        foreach ($models as $tag) {
            if (! in_array($tag->scope_key, $this->visibleScopeKeys($taggable, $tag), true)) {
                if ($strict) {
                    throw TagScopeMismatchException::forTag((string) $tag->slug);
                }

                continue;
            }

            if ($tag->trashed()) {
                if ($strict) {
                    throw TagNotFoundException::trashed();
                }

                continue;
            }

            if ($explicit !== null && $tag->type !== $explicit->name) {
                throw TagTypeMismatchException::expected($explicit->name, $tag->type);
            }

            $type = $types[$tag->type] ??= $this->registry->resolve($tag->type);
            $tags[$type->name][(string) $tag->getKey()] = $tag;
        }

        $stringType = null;

        if ($strings !== []) {
            $stringType = $explicit ?? $this->registry->defaultType() ?? throw UnknownTagTypeException::typeRequired();
            $types[$stringType->name] ??= $stringType;
        }

        // The types the model may carry, from both sides.
        if ($strict) {
            foreach ($types as $type) {
                if (! $type->allows($taggable)) {
                    throw TagTypeNotAllowedException::onModel($type->name, class_basename($taggable));
                }
            }
        }

        $missing = [];

        if ($stringType !== null) {
            [$found, $missing] = $this->resolveStrings($taggable, $stringType, $strings, $strict);

            foreach ($found as $tag) {
                $tags[$stringType->name][(string) $tag->getKey()] = $tag;
            }
        }

        return new ResolvedSelection(
            types: $types,
            tags: array_map(static fn (array $byKey): array => array_values($byKey), $tags),
            missing: $stringType !== null && $missing !== [] ? [$stringType->name => $missing] : [],
        );
    }

    /**
     * The scope keys a tag input may come from for this model: its type's candidates. A type that
     * no longer resolves counts as not global-only, so the model's scope and global.
     *
     * @return list<string>
     */
    private function visibleScopeKeys(Model&Taggable $taggable, Tag $tag): array
    {
        $type = $this->registry->find($tag->type);

        if ($type !== null) {
            return Taggings::candidateScopeKeys($taggable, $type);
        }

        $scope = $taggable->tagScope();

        return $scope === null ? [ScopeKey::GLOBAL] : [ScopeKey::for($scope), ScopeKey::GLOBAL];
    }

    /**
     * @param  list<int>  $keys
     * @return list<Tag>
     */
    private function loadKeys(array $keys, bool $strict): array
    {
        if ($keys === []) {
            return [];
        }

        $valid = array_values(array_filter($keys, KeyShape::isTagKey(...)));
        $found = TagModel::query()->withTrashed()->whereKey($valid)->get()
            ->keyBy(static fn (Tag $tag): string => (string) $tag->getKey());

        $loaded = [];

        foreach ($keys as $key) {
            $tag = $found->get((string) $key);

            if ($tag === null) {
                if ($strict) {
                    throw TagNotFoundException::forKey($key);
                }

                continue;
            }

            $loaded[] = $tag;
        }

        return $loaded;
    }

    /**
     * @param  list<string>  $strings
     * @return array{0: list<Tag>, 1: array<string, array<string, string>>}
     */
    private function resolveStrings(Model&Taggable $taggable, TagType $type, array $strings, bool $strict): array
    {
        $usable = [];

        foreach ($strings as $string) {
            if ($strict) {
                TagName::normalize($string);
            }

            if (TagName::normalizeOrNull($string) !== null) {
                $usable[] = $string;
            }
        }

        $found = TagLookup::byStrings($type, Taggings::candidateScopeKeys($taggable, $type), $usable);
        $missing = [];

        foreach ($usable as $string) {
            if (isset($found[$string]) || ! $strict) {
                continue;
            }

            if ($type->curated) {
                throw TagNotFoundException::forName((string) TagName::normalizeOrNull($string), $type->name);
            }

            $map = TagName::map($string);
            $missing[TagSlugs::slugify(TagName::lookup($map), Translatable::currentLocale())] = $map;
        }

        return [array_values($found), $missing];
    }
}
