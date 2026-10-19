<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Requests;

use RoundlyConsulting\Tags\Support\TagAttributes;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * A new tag: a name map (at least one locale), and optionally a description map, manual slugs
 * (curated types), a colour, an icon, a parent key and a position.
 *
 * @internal
 */
final class StoreTagRequest extends TagsRequest
{
    protected function endpointRules(): array
    {
        return [
            ...Translatable::rules('name', true, ['max:'.TagsConfig::nameMaxLength()]),
            ...Translatable::rules('description', false, ['max:'.TagsConfig::descriptionMaxLength()]),
            ...Translatable::rules('slug', false, ['max:'.TagsConfig::slugMaxLength()]),
            'color' => ['nullable', 'string', 'max:7'],
            'icon' => ['nullable', 'string', 'max:64'],
            'parent' => ['nullable', 'string', 'max:64'],
            'position' => ['nullable', 'integer', 'min:0', 'max:'.TagAttributes::MAX_POSITION],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_int($this->input('parent'))) {
            $this->merge(['parent' => (string) $this->input('parent')]);
        }
    }
}
