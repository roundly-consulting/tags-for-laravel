<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Tags\Support\ScopeKey;
use RoundlyConsulting\Tags\Support\TagsConfig;
use RoundlyConsulting\Tags\Types\TagType;

/**
 * The API's authorization, through the host's gates only — the package defines none, so an
 * undefined ability denies. `manage` receives the type name and the target scope (null: a global
 * write), so a host can keep tenant admins off global tags. Answers are memoised per request.
 *
 * @internal
 */
final class Access
{
    private const MEMO = 'tags.access';

    public static function canManage(Request $request, TagType $type, ?Model $scope): bool
    {
        $key = 'manage:'.$type->name.':'.ScopeKey::for($scope);

        return self::memo($request, $key, static fn (): bool => self::gate($request)->allows(TagsConfig::manageAbility(), [$type->name, $scope]));
    }

    public static function authorizeManage(Request $request, TagType $type, ?Model $scope): void
    {
        if (! self::canManage($request, $type, $scope)) {
            throw new AuthorizationException;
        }
    }

    public static function authorizeView(Request $request, TagType $type, ?Model $scope): void
    {
        if (! self::canView($request, $type, $scope)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Whether the caller may read the type: no `view` ability means everyone may.
     */
    public static function canView(Request $request, TagType $type, ?Model $scope): bool
    {
        $ability = TagsConfig::viewAbility();

        if ($ability === null) {
            return true;
        }

        $key = 'view:'.$type->name.':'.ScopeKey::for($scope);

        return self::memo($request, $key, static fn (): bool => self::gate($request)->allows($ability, [$type->name, $scope]));
    }

    /**
     * Whether the caller may use the scope model a `?scope=` reference names.
     */
    public static function canUseScope(Request $request, Model $scope): bool
    {
        return self::gate($request)->allows(TagsConfig::scopeAbility(), [$scope]);
    }

    /**
     * Whether the caller may see the type at all: a private type is visible to its managers only.
     */
    public static function canSee(Request $request, TagType $type, ?Model $scope): bool
    {
        return ! $type->private || self::canManage($request, $type, $scope);
    }

    /**
     * The request the app is serving (the one resources render with), not a FormRequest copy.
     */
    public static function canonical(Request $request): Request
    {
        $current = app()->bound('request') ? app('request') : null;

        return $current instanceof Request ? $current : $request;
    }

    private static function gate(Request $request): Gate
    {
        return app(Gate::class)->forUser($request->user());
    }

    /**
     * @param  callable(): bool  $resolve
     */
    private static function memo(Request $request, string $key, callable $resolve): bool
    {
        // One memo per HTTP request: a FormRequest is a copy of the request the resources render.
        $request = self::canonical($request);
        $memo = $request->attributes->get(self::MEMO);
        $memo = is_array($memo) ? $memo : [];
        $known = $memo[$key] ?? null;

        if (is_bool($known)) {
            return $known;
        }

        $answer = $resolve();
        $memo[$key] = $answer;
        $request->attributes->set(self::MEMO, $memo);

        return $answer;
    }
}
