<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Tags\DataTransferObjects\TagData;
use RoundlyConsulting\Tags\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tags\Http\Access;
use RoundlyConsulting\Tags\Http\Queries\TagIndexQuery;
use RoundlyConsulting\Tags\Http\Requests\DestroyTagRequest;
use RoundlyConsulting\Tags\Http\Requests\IndexTagsRequest;
use RoundlyConsulting\Tags\Http\Requests\ReadTagsRequest;
use RoundlyConsulting\Tags\Http\Requests\StoreTagRequest;
use RoundlyConsulting\Tags\Http\Requests\UpdateTagRequest;
use RoundlyConsulting\Tags\Http\Resources\TagNodeResource;
use RoundlyConsulting\Tags\Http\Resources\TagResource;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\Messages;
use RoundlyConsulting\Tags\Support\NotGiven;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\Support\TagsConfig;

/**
 * The tags of one type: list (or tree), show, create, update, delete, restore.
 *
 * @internal
 */
final class TagController extends Controller
{
    public function index(IndexTagsRequest $request, string $type): JsonResponse
    {
        $tagType = $this->type($request, $type);
        $scope = $request->scopeModel();
        $manager = Access::canManage($request, $tagType, $scope);
        $handle = $this->handle($request, $tagType);
        $this->share($request, $scope, $manager && $request->wantsTranslations());

        if ($request->wantsTree() && $tagType->hierarchical) {
            $max = TagsConfig::maxTreeNodes();

            if ($handle->query()->count() > $max) {
                return new JsonResponse(['message' => Messages::get('messages.api.tree_too_large', ['max' => $max]), 'code' => 'tree_too_large'], 422);
            }

            return TagNodeResource::collection($handle->tree())->response();
        }

        $page = TagIndexQuery::for($tagType->name, $handle->readScopeKeys(), $request, $manager)
            ->orderBy(TagModel::make()->getQualifiedKeyName())
            ->paginate($request->perPage());

        $scoped = $page->getCollection()->filter(static fn (mixed $tag): bool => $tag instanceof Tag && $tag->scope_type !== null);
        (new Collection($scoped->all()))->loadMissing('scope');

        return TagResource::collection($page)->response();
    }

    public function show(ReadTagsRequest $request, string $type, string $tag): JsonResponse
    {
        $tagType = $this->type($request, $type);
        $model = $this->pathTag($request, $this->handle($request, $tagType), $tag);
        $this->manager()->loadAncestors([$model]);
        $this->share($request, $request->scopeModel());

        return (new TagResource($model))->response();
    }

    public function store(StoreTagRequest $request, string $type): JsonResponse
    {
        $tagType = $this->type($request, $type);
        $scope = $request->scopeModel();
        Access::authorizeManage($request, $tagType, $scope);
        $validated = $request->validated();

        $tag = $this->handle($request, $tagType)->create(new TagData(
            name: self::map($validated['name'] ?? []),
            description: self::map($validated['description'] ?? []),
            slug: self::map($validated['slug'] ?? []),
            color: self::string($validated['color'] ?? null),
            icon: self::string($validated['icon'] ?? null),
            parent: $this->bodyTag($request, $tagType, $validated['parent'] ?? null),
            position: self::integer($validated['position'] ?? null),
        ));

        $this->share($request, $scope);

        return (new TagResource($tag))->response()->setStatusCode(201);
    }

    public function update(UpdateTagRequest $request, string $type, string $tag): JsonResponse
    {
        $tagType = $this->type($request, $type);
        $scope = $request->scopeModel();
        Access::authorizeManage($request, $tagType, $scope);
        $handle = $this->handle($request, $tagType);
        $model = $this->pathTag($request, $handle, $tag, write: true);
        $validated = $request->validated();

        $updated = $handle->update($model, new UpdateTagData(
            name: array_key_exists('name', $validated) ? self::map($validated['name']) : new NotGiven,
            description: array_key_exists('description', $validated) ? ($validated['description'] === null ? null : self::map($validated['description'])) : new NotGiven,
            slug: array_key_exists('slug', $validated) ? self::map($validated['slug']) : new NotGiven,
            color: array_key_exists('color', $validated) ? self::string($validated['color']) : new NotGiven,
            icon: array_key_exists('icon', $validated) ? self::string($validated['icon']) : new NotGiven,
        ));

        $this->share($request, $scope);

        return (new TagResource($updated))->response();
    }

    public function destroy(DestroyTagRequest $request, string $type, string $tag): JsonResponse
    {
        $tagType = $this->type($request, $type);
        $scope = $request->scopeModel();
        Access::authorizeManage($request, $tagType, $scope);
        $handle = $this->handle($request, $tagType);

        $deleted = $handle->delete($this->pathTag($request, $handle, $tag, write: true), $request->children());
        $this->share($request, $scope);

        return (new TagResource($deleted))->response();
    }

    public function restore(ReadTagsRequest $request, string $type, string $tag): JsonResponse
    {
        $tagType = $this->type($request, $type);
        $scope = $request->scopeModel();
        Access::authorizeManage($request, $tagType, $scope);
        $handle = $this->handle($request, $tagType);

        $restored = $handle->restore($this->pathTag($request, $handle, $tag, write: true, withTrashed: true));
        $this->share($request, $scope);

        return (new TagResource($restored))->response();
    }

    /**
     * @return array<string, string>
     */
    private static function map(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $locale => $text) {
            if (is_string($text) && $text !== '') {
                $map[(string) $locale] = $text;
            }
        }

        return $map;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
