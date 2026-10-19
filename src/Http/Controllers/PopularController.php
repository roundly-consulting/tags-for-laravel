<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Controllers;

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

        $tags = $this->handle($request, $tagType)->popular($request->limit(), $request->on());
        self::loadScopes($tags);

        return TagResource::collection($tags)->response();
    }
}
