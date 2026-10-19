<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How a scoped tag's owner renders by default: its key and its class basename — never the class
 * name. Point `tags.resources.scope` at your own resource to add a name or anything else; it
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
        return ['id' => $this->resource->getKey(), 'type' => class_basename($this->resource)];
    }
}
