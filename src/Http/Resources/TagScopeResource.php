<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tags\Support\TagsConfig;

/**
 * How a scoped tag's owner renders by default: its key and its `tags.scopes` alias — the one
 * `?scope=alias:key` takes — or, for a scope model without an alias, its class basename; never the
 * class name. Point `tags.resources.scope` at your own resource to add a name or anything else; it
 * receives the scope model.
 *
 * @property Model $resource
 */
final class TagScopeResource extends JsonResource
{
    /**
     * @return array{id: mixed, type: string}
     */
    public function toArray(Request $request): array
    {
        $alias = array_search($this->resource::class, TagsConfig::scopes(), true);

        return ['id' => $this->resource->getKey(), 'type' => is_string($alias) ? $alias : class_basename($this->resource)];
    }
}
