<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Requests;

use RoundlyConsulting\Tags\Support\TagsConfig;

/**
 * The tag list: query-builder filters and sorts, a page size up to `tags.routes.max_per_page`,
 * `?tree=1` for nested nodes and `?include=translations` (managers only).
 *
 * @internal
 */
final class IndexTagsRequest extends TagsRequest
{
    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return is_numeric($perPage) ? (int) $perPage : min(20, TagsConfig::maxPerPage());
    }

    public function wantsTree(): bool
    {
        return filter_var($this->validated('tree', false), FILTER_VALIDATE_BOOL);
    }

    public function wantsTranslations(): bool
    {
        return $this->validated('include') === 'translations';
    }

    protected function endpointRules(): array
    {
        return [
            'filter' => ['nullable', 'array'],
            'sort' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.TagsConfig::maxPerPage()],
            'tree' => ['nullable', 'boolean'],
            'include' => ['nullable', 'string', 'in:translations'],
        ];
    }
}
