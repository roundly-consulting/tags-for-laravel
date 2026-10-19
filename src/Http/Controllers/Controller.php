<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagsException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagTypeException;
use RoundlyConsulting\Tags\Handles\TypeTags;
use RoundlyConsulting\Tags\Http\Access;
use RoundlyConsulting\Tags\Http\PathNotFound;
use RoundlyConsulting\Tags\Http\Requests\TagsRequest;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Support\KeyShape;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagLookup;
use RoundlyConsulting\Tags\Support\TagModel;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Types\TagType;
use RoundlyConsulting\Tags\Types\TagTypeRegistry;

/**
 * The base of the API's controllers. Errors are mapped here — never in the host's exception
 * handler — through the exceptions' own presentation (`errorCode()`, `clientMessage()`,
 * `status()`, `isNotFound()`): a path segment that names nothing the caller may see is a 404, a
 * body reference a 422, and a tag of another scope answers exactly like a missing one. Anything
 * else (validation, authorization, configuration) is Laravel's to answer.
 *
 * Every write goes through the manager, so `Tags::fake()` records API calls too.
 *
 * @internal
 */
abstract class Controller
{
    /**
     * @param  array<array-key, mixed>  $parameters
     */
    public function callAction(string $method, array $parameters): mixed
    {
        try {
            return $this->{$method}(...array_values($parameters));
        } catch (PathNotFound $notFound) {
            return self::error($notFound->reason, 404);
        } catch (TagsException $exception) {
            return self::error($exception, $exception->status());
        }
    }

    public static function error(TagsException $exception, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $exception->clientMessage(), 'code' => $exception->errorCode()], $status);
    }

    protected function manager(): TagsManager
    {
        return Container::getInstance()->make(TagsManager::class);
    }

    /**
     * The `{type}` segment: a defined type the caller may see (a private type is visible to its
     * managers only) and read.
     */
    protected function type(TagsRequest $request, string $name): TagType
    {
        $type = TagTypeRegistry::isValidName($name) ? app(TagTypeRegistry::class)->find($name) : null;
        $scope = $request->scopeModel();

        if ($type === null || ! Access::canSee($request, $type, $scope)) {
            throw new PathNotFound(UnknownTagTypeException::named($name));
        }

        Access::authorizeView($request, $type, $scope);

        return $type;
    }

    /**
     * The catalog the request reads and writes: its scope (plus the global tags for reads,
     * unless `scope_only`).
     */
    protected function handle(TagsRequest $request, TagType $type): TypeTags
    {
        return $this->manager()->type($type->name)->within($request->scopeModel(), $request->includeGlobal());
    }

    /**
     * The `{tag}` segment: a key (always a key when it fits the key type), else a slug or name,
     * in the readable scopes — or, for a write, in exactly the request's scope. With
     * `$withTrashed` (restore), a slug or name that names no live tag names a deleted one of
     * exactly the request's scope.
     */
    protected function pathTag(TagsRequest $request, TypeTags $handle, string $segment, bool $write = false, bool $withTrashed = false): Tag
    {
        $keys = $write ? [ScopeKey::for($request->scopeModel())] : $handle->readScopeKeys();
        $type = $handle->definition();

        if (KeyShape::isTagKey($segment)) {
            $query = TagModel::query()->where('type', $type->name)->whereIn('scope_key', $keys)->whereKey($segment);
            $tag = ($withTrashed ? $query->withTrashed() : $query)->first();
        } else {
            $tag = TagLookup::byStrings($type, $keys, [$segment])[$segment] ?? null;

            if ($tag === null && $withTrashed) {
                $tag = $handle->findTrashed($segment);
            }
        }

        return $tag ?? throw new PathNotFound(TagNotFoundException::generic());
    }

    /**
     * A tag a request body names by key, in exactly the request's scope; another scope's tag
     * answers like a missing one (422 `tag_not_found`).
     */
    protected function bodyTag(TagsRequest $request, TagType $type, mixed $key): ?Tag
    {
        if ($key === null || $key === '') {
            return null;
        }

        $tag = (is_string($key) || is_int($key)) && KeyShape::isTagKey($key)
            ? TagModel::query()->where('type', $type->name)->where('scope_key', ScopeKey::for($request->scopeModel()))->whereKey($key)->first()
            : null;

        return $tag ?? throw TagNotFoundException::generic();
    }

    /**
     * Remember what resources need: the request's scope and the translations switch.
     */
    protected function share(TagsRequest $request, ?Model $scope, bool $translations = false): void
    {
        $canonical = Access::canonical($request);
        $canonical->attributes->set('tags.request_scope', $scope);
        $canonical->attributes->set('tags.include_translations', $translations);
    }
}
