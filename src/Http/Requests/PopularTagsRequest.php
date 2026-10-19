<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Support\MorphAliases;
use RoundlyConsulting\Tags\Support\TagsConfig;

/**
 * The most used tags: a limit up to `tags.popular.max_limit` and `?on=` — an alias from
 * `tags.taggables`, never a class name.
 *
 * @internal
 */
final class PopularTagsRequest extends TagsRequest
{
    public function limit(): ?int
    {
        $limit = $this->validated('limit');

        return is_numeric($limit) ? (int) $limit : null;
    }

    /**
     * @return class-string<Model>|null
     */
    public function on(): ?string
    {
        $alias = $this->validated('on');

        return is_string($alias) && $alias !== '' ? MorphAliases::taggable($alias) : null;
    }

    protected function endpointRules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.TagsConfig::popularMaxLimit()],
            'on' => ['nullable', 'string', 'max:64'],
        ];
    }
}
