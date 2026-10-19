<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Enums\EmptySourcePolicy;
use RoundlyConsulting\Sluggable\Enums\LocaleFallback;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;
use RoundlyConsulting\Sluggable\Support\Slugger;

/**
 * The tag slug definition and the identity rule built on it: a string names the tag whose slug,
 * in some locale of the chain, equals that locale's slug of the string — computed with the very
 * function and format sluggable generates with, never a re-implementation.
 *
 * Every rule that decides identity is pinned here rather than inherited from sluggable's
 * defaults: the chain (`any`), uniqueness per (type, scope) with trashed rows included, and no
 * suffixes — a taken slug is the same tag, or a duplicate.
 *
 * @internal
 */
final class TagSlugs
{
    public static function definition(bool $stable): SlugDefinition
    {
        return SlugDefinition::for('slug')
            ->from('name')
            ->localized()
            ->fallback(LocaleFallback::Any)
            ->uniqueWithin('type', 'scope_key')
            ->perLocaleUniqueness()
            ->includeTrashed()
            ->maxLength(TagsConfig::slugMaxLength())
            ->separator(TagsConfig::slugSeparator())
            ->dictionary(TagsConfig::slugDictionary())
            ->unicode(TagsConfig::unicodeSlugs())
            ->suffixUsing(new NoSuffix)
            ->retries(0)
            ->manual(ManualSlugPolicy::Strict)
            ->onUpdate($stable ? UpdatePolicy::IfEmpty : UpdatePolicy::WhenSourceChanges)
            ->whenEmptySource(EmptySourcePolicy::Skip)
            ->keepHistory(false);
    }

    /**
     * The definition resolved against a blank tag: the format and chain every lookup uses.
     */
    public static function resolved(): ResolvedSlugDefinition
    {
        return self::definition(false)->resolve(TagModel::make());
    }

    /**
     * The locales a string is looked up in, in order: current, fallback, then every other one.
     *
     * @return list<string>
     */
    public static function chain(): array
    {
        return self::resolved()->chain(app(SlugLocales::class));
    }

    /**
     * The supported locales (each gets a slug index).
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return app(SlugLocales::class)->supported();
    }

    /**
     * The slug of `$input` in `$locale`, exactly as sluggable would generate it.
     */
    public static function slugify(string $input, string $locale, ?ResolvedSlugDefinition $definition = null): string
    {
        $definition ??= self::resolved();

        return Slugger::slugify($input, $definition->format, $definition->languageFor($locale), $locale);
    }

    /**
     * Whether a manual slug is already in the shape the pipeline produces.
     */
    public static function isNormalised(string $slug, string $locale): bool
    {
        return $slug !== '' && self::slugify($slug, $locale) === $slug;
    }
}
