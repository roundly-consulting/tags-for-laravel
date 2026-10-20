<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Tags\Http\Requests\PopularTagsRequest;
use RoundlyConsulting\Tags\Http\Resources\TagResource;

/**
 * `GET /tags/{type}/popular?limit=&on=` — the most used tags, by usage or live on one taggable
 * alias.
 *
 * @internal
 */
final class PopularController extends Controller
{
    public function __invoke(PopularTagsRequest $request, string $type): JsonResponse
    {
        $tagType = $this->type($request, $type);
        $this->share($request, $request->scopeModel());

        // An empty constraint makes the live count go through the class's own query, so its
        // global scopes (soft deletes, a host's tenancy) apply; local scopes need `popular(where:)`.
        $on = $request->on();
        $where = $on === null ? null : static fn (Builder $taggables): Builder => $taggables;

        $tags = $this->handle($request, $tagType)->popular($request->limit(), $on, $where);
        self::loadScopes($tags);

        return TagResource::collection($tags)->response();
    }
}
