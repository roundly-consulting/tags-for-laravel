<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\User;

/**
 * A signed-in user (an admin may manage every type in every scope).
 */
function apiUser(bool $admin = false): User
{
    $user = User::query()->create(['name' => $admin ? 'Admin' : 'Reader', 'is_admin' => $admin]);
    test()->actingAs($user);

    return $user;
}

/**
 * The host's gates as an app defines them: admins manage; anyone may read a scope.
 */
function defineTagGates(): void
{
    Gate::define('tags.manage', static fn (User $user, string $type, ?Model $scope = null): bool => $user->is_admin);
    Gate::define('tags.scope', static fn (User $user, Model $scope): bool => true);
}
