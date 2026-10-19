<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Requests;

/**
 * A reorder: `{parent: key|null, order: [keys]}`.
 *
 * @internal
 */
final class ReorderTagsRequest extends TagsRequest
{
    protected function endpointRules(): array
    {
        return [
            'parent' => ['nullable', 'string', 'max:64'],
            'order' => ['present', 'array', 'max:1000'],
            'order.*' => ['required', 'string', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $order = $this->input('order');

        $this->merge([
            'parent' => is_int($this->input('parent')) ? (string) $this->input('parent') : $this->input('parent'),
            'order' => is_array($order) ? array_map(static fn (mixed $key): mixed => is_int($key) ? (string) $key : $key, $order) : $order,
        ]);
    }
}
