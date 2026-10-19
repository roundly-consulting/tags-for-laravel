<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Requests;

use RoundlyConsulting\Tags\Support\TagsConfig;

/**
 * Autocomplete: `?q=` of 1–64 characters and a limit up to `tags.suggest.max_limit`.
 *
 * @internal
 */
final class SuggestTagsRequest extends TagsRequest
{
    public function term(): string
    {
        $term = $this->validated('q');

        return is_string($term) ? $term : '';
    }

    public function limit(): ?int
    {
        $limit = $this->validated('limit');

        return is_numeric($limit) ? (int) $limit : null;
    }

    protected function endpointRules(): array
    {
        return [
            'q' => ['required', 'string', 'min:1', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.TagsConfig::suggestMaxLimit()],
        ];
    }
}
