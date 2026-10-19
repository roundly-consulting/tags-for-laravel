<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Tags\Http\Access;
use RoundlyConsulting\Tags\Http\Requests\MoveTagRequest;
use RoundlyConsulting\Tags\Http\Resources\TagResource;

/**
 * `POST /tags/{type}/{tag}/move` — `{parent: key|null, position}`.
 *
 * @internal
 */
final class MoveTagController extends Controller
{
    public function __invoke(MoveTagRequest $request, string $type, string $tag): JsonResponse
    {
        $tagType = $this->type($request, $type);
        $scope = $request->scopeModel();
        Access::authorizeManage($request, $tagType, $scope);
        $handle = $this->handle($request, $tagType);
        $validated = $request->validated();

        $moved = $handle->move(
            $this->pathTag($request, $handle, $tag, write: true),
            $this->bodyTag($request, $tagType, $validated['parent'] ?? null),
            self::integer($validated['position'] ?? null),
        );

        $this->share($request, $scope);

        return (new TagResource($moved))->response();
    }
}
