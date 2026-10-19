<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The API's authenticated user.
 */
final class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_admin' => 'boolean'];
    }
}
