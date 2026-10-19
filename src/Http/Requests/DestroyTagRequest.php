<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Requests;

use Illuminate\Validation\Rule;
use RoundlyConsulting\Tags\Enums\TagChildren;

/**
 * A delete: `?children=refuse|reparent|delete` (refuse by default).
 *
 * @internal
 */
final class DestroyTagRequest extends TagsRequest
{
    public function children(): TagChildren
    {
        $children = $this->validated('children');

        return is_string($children) ? TagChildren::from($children) : TagChildren::Refuse;
    }

    protected function endpointRules(): array
    {
        return [
            'children' => ['nullable', Rule::enum(TagChildren::class)],
        ];
    }
}
