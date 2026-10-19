<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Contracts\Taggable;

/**
 * Pest matchers for host suites. Call `TagExpectations::register()` from `tests/Pest.php`:
 *
 * ```php
 * expect($event)->toHaveTags(['live'], EventTagType::Label);
 * expect($event)->toHaveNoTags(EventTagType::Category);
 * ```
 *
 * Defined only when Pest's `expect()` exists, so nothing here reaches the runtime.
 */
final class TagExpectations
{
    public static function register(): void
    {
        if (! function_exists('expect')) {
            return;
        }

        expect()->extend('toHaveTags', function (array $tags, mixed $type = null): mixed {
            /** @var Model&Taggable $taggable */
            $taggable = $this->value;

            foreach ($tags as $tag) {
                expect($taggable->hasTag($tag, $type))->toBeTrue('Expected the model to carry the tag ['.(is_object($tag) ? $tag->getKey() : $tag).'].');
            }

            return $this;
        });

        expect()->extend('toHaveNoTags', function (mixed $type = null): mixed {
            /** @var Model&Taggable $taggable */
            $taggable = $this->value;

            $tags = $type === null ? $taggable->tags()->get() : $taggable->tagsOf($type);

            expect($tags)->toHaveCount(0, 'Expected the model to carry no tags.');

            return $this;
        });
    }
}
