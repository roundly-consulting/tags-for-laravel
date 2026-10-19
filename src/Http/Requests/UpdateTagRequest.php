<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Requests;

use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Translatable\Facades\Translatable;

/**
 * Changes to a tag: only the fields sent change; `null` clears a description, colour or icon.
 *
 * @internal
 */
final class UpdateTagRequest extends TagsRequest
{
    protected function endpointRules(): array
    {
        $description = Translatable::rules('description', false, ['max:'.TagsConfig::descriptionMaxLength()]);
        $description['description'] = ['sometimes', 'nullable', 'array'];

        return [
            ...Translatable::rules('name', false, ['max:'.TagsConfig::nameMaxLength()]),
            ...$description,
            ...Translatable::rules('slug', false, ['max:'.TagsConfig::slugMaxLength()]),
            'color' => ['sometimes', 'nullable', 'string', 'max:7'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
