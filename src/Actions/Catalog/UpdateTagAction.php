<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Actions\Catalog;

use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Sluggable\Exceptions\SlugAlreadyTakenException;
use RoundlyConsulting\Tags\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tags\Events\TagUpdated;
use RoundlyConsulting\Tags\Exceptions\DuplicateTagException;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\NotGiven;
use RoundlyConsulting\Tags\Support\TagAttributes;
use RoundlyConsulting\Tags\Support\Taggings;
use RoundlyConsulting\Tags\Support\TagLookup;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagName;
use RoundlyConsulting\Tags\Support\TagSlugs;
use RoundlyConsulting\Tags\Support\TagTransaction;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * Rename a tag, or change its description, manual slug, colour or icon. A name or description
 * merges per locale into the stored one; a blank or null description entry clears that locale. A locale's slug follows its name unless the type has
 * stable slugs; a rename onto a slug another tag holds is refused — pointing to `restore()` when
 * a deleted tag holds it, because a deleted name stays reserved.
 */
final readonly class UpdateTagAction
{
    public function __construct(
        private TagTypeRegistry $registry,
    ) {}

    public function execute(Tag $tag, UpdateTagData $data): Tag
    {
        $type = $this->registry->resolve($tag->type);

        $name = $data->name instanceof NotGiven ? null : TagName::map($data->name);
        $description = $data->description instanceof NotGiven ? null : TagAttributes::description($data->description);
        $cleared = $data->description instanceof NotGiven ? [] : TagAttributes::clearedLocales($data->description);
        $slug = $data->slug instanceof NotGiven ? null : TagAttributes::slug($data->slug);
        $color = $data->color instanceof NotGiven ? null : TagAttributes::color($data->color);
        $icon = $data->icon instanceof NotGiven ? null : TagAttributes::icon($data->icon);

        if ($slug !== null && $slug !== [] && ! $type->curated) {
            throw InvalidTagException::manualSlugOnFreeForm($type->name);
        }

        return TagTransaction::run(static function () use ($tag, $type, $data, $name, $description, $cleared, $slug, $color, $icon): Tag {
            // One sweep: the tag and every holder of a slug this change may take, read first
            // without a lock, so the holders probe below finds them held.
            Taggings::lockTags([$tag->getKey(), ...TagLookup::holders($type, $tag->scope_key, self::candidateSlugs($name, $slug), except: $tag)->modelKeys()]);

            $fresh = TagModel::query()->whereKey($tag->getKey())->lockForUpdate()->first()
                ?? throw TagNotFoundException::trashed();

            $changed = [];
            $probe = [];

            if ($name !== null) {
                $current = $fresh->getTranslations('name');
                $merged = [...$current, ...$name];

                if ($merged !== $current) {
                    $changed[] = 'name';

                    // A slug follows its changed name; on a stable-slug type only a locale without a
                    // slug yet takes one.
                    foreach ($name as $locale => $value) {
                        $takesSlug = $type->stableSlugs
                            ? $fresh->slugFor($locale) === null
                            : ($current[$locale] ?? null) !== $value;

                        if ($takesSlug) {
                            $probe[$locale] = TagSlugs::slugify($value, $locale);
                        }
                    }

                    $fresh->setTranslations('name', $merged);
                }
            }

            if ($slug !== null && $slug !== []) {
                $current = $fresh->getTranslations('slug');
                $merged = [...$current, ...$slug];

                if ($merged !== $current) {
                    $changed[] = 'slug';
                    $probe = [...$probe, ...array_diff_assoc($slug, $current)];
                    $fresh->setTranslations('slug', $merged);
                }
            }

            if (! $data->description instanceof NotGiven) {
                // `null` clears every locale; a blank or null entry clears its own.
                $merged = $data->description === null
                    ? []
                    : array_diff_key([...$fresh->getTranslations('description'), ...($description ?? [])], array_flip($cleared));

                if ($merged !== $fresh->getTranslations('description')) {
                    $changed[] = 'description';
                    $fresh->setTranslations('description', $merged);
                }
            }

            if (! $data->color instanceof NotGiven && $fresh->color !== $color) {
                $changed[] = 'color';
                $fresh->color = $color;
            }

            if (! $data->icon instanceof NotGiven && $fresh->icon !== $icon) {
                $changed[] = 'icon';
                $fresh->icon = $icon;
            }

            if ($changed === []) {
                return $fresh;
            }

            $holders = TagLookup::holders($type, $fresh->scope_key, $probe, except: $fresh, lock: true);

            foreach ($probe as $locale => $value) {
                $holder = $holders->first(static fn (Tag $other): bool => $other->slugFor($locale) === $value);

                if ($holder !== null) {
                    throw $holder->trashed()
                        ? DuplicateTagException::heldByTrashed($value, $locale)
                        : DuplicateTagException::forSlug($value, $locale);
                }
            }

            try {
                TagTransaction::savepoint(static fn (): bool => $fresh->save());
            } catch (UniqueConstraintViolationException|SlugAlreadyTakenException) {
                throw DuplicateTagException::generic();
            }

            event(new TagUpdated($fresh, $changed));

            return $fresh;
        });
    }

    /**
     * Every slug the change could give the tag: each new name's, per locale, and each manual one.
     *
     * @param  array<string, string>|null  $name
     * @param  array<string, string>|null  $slug
     * @return array<string, string>
     */
    private static function candidateSlugs(?array $name, ?array $slug): array
    {
        $slugs = [];

        foreach ($name ?? [] as $locale => $value) {
            $slugs[$locale] = TagSlugs::slugify($value, $locale);
        }

        return [...$slugs, ...($slug ?? [])];
    }
}
