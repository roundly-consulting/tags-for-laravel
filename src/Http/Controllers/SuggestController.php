<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Tags\Http\Requests\SuggestTagsRequest;
use RoundlyConsulting\Tags\Http\Resources\TagResource;

/**
 * `GET /tags/{type}/suggest?q=` — autocomplete, throttled by `tags-suggest`.
 *
 * @internal
 */
final class SuggestController extends Controller
{
    public function __invoke(SuggestTagsRequest $request, string $type): JsonResponse
    {
        $tagType = $this->type($request, $type);
        $this->share($request, $request->scopeModel());

        return TagResource::collection($this->handle($request, $tagType)->suggest($request->term(), $request->limit()))->response();
    }
}
