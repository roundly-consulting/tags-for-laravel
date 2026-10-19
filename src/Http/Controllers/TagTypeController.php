<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Tags\Http\Access;
use RoundlyConsulting\Tags\Http\Requests\ReadTagsRequest;
use RoundlyConsulting\Tags\Http\Resources\TagTypeResource;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * `GET /tag-types` and `GET /tag-types/{type}`.
 *
 * @internal
 */
final class TagTypeController extends Controller
{
    public function index(ReadTagsRequest $request): JsonResponse
    {
        $scope = $request->scopeModel();
        $types = $this->manager()->types()->filter(static fn (TagType $type): bool => Access::canSee($request, $type, $scope));

        return TagTypeResource::collection($types->values())->response();
    }

    public function show(ReadTagsRequest $request, string $type): JsonResponse
    {
        return (new TagTypeResource($this->type($request, $type)))->response();
    }
}
