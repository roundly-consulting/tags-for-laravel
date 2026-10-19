<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Requests;

use RoundlyConsulting\Tags\Support\TagAttributes;

/**
 * A move: `{parent: key|null, position}`.
 *
 * @internal
 */
final class MoveTagRequest extends TagsRequest
{
    protected function endpointRules(): array
    {
        return [
            'parent' => ['present', 'nullable', 'string', 'max:64'],
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
