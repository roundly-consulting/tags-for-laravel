<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Tags\Http\Access;
use RoundlyConsulting\Tags\Http\Requests\ReorderTagsRequest;
use RoundlyConsulting\Tags\Http\Resources\TagResource;

/**
 * `POST /tags/{type}/reorder` — `{parent: key|null, order: [keys]}`.
 *
 * @internal
 */
final class ReorderTagsController extends Controller
{
    public function __invoke(ReorderTagsRequest $request, string $type): JsonResponse
    {
        $tagType = $this->type($request, $type);
        $scope = $request->scopeModel();
        Access::authorizeManage($request, $tagType, $scope);
        $validated = $request->validated();
        $order = [];

        foreach (is_array($validated['order'] ?? null) ? $validated['order'] : [] as $key) {
            if (is_string($key)) {
                $order[] = $key;
            }
        }

        $reordered = $this->handle($request, $tagType)->reorder($this->bodyTag($request, $tagType, $validated['parent'] ?? null), $order);
        $this->share($request, $scope);

        return TagResource::collection($reordered)->response();
    }
}
