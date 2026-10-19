<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\Http\Resources\TagScopeResource;
use RoundlyConsulting\Tags\Models\Tag;

return [

    /*
    |--------------------------------------------------------------------------
    | Key types
    |--------------------------------------------------------------------------
    |
    | `primary_key_type` is the type of the package's own keys: tags.id,
    | tags.parent_id and taggables.tag_id. `key_type` is the type of the keys
    | the package points at: every taggable model (taggable_id) and every scope
    | model (scope_id) must use it. Both are bigint, uuid or ulid, and both are
    | frozen once the migrations ran.
    |
    */

    'primary_key_type' => env('TAGS_PRIMARY_KEY_TYPE', 'bigint'),

    'key_type' => env('TAGS_KEY_TYPE', 'bigint'),

    /*
    |--------------------------------------------------------------------------
    | Tables and the tag model
    |--------------------------------------------------------------------------
    |
    | Rename the tables before migrating when the host already owns a `tags`
    | table. The tag model may be swapped for a subclass of the packaged one.
    |
    */

    'tables' => [
        'tags' => 'tags',
        'taggables' => 'taggables',
        'locks' => 'tag_locks',
    ],

    'models' => [
        'tag' => Tag::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tag types
    |--------------------------------------------------------------------------
    |
    | `default_type` is the type a plain string names when a call gives none.
    | Unless something defines it, it is a flat, free-form, unlimited type.
    | Blank means there is no default, so every string must name a type.
    |
    | `types` declares the host's own types (name => rules) and `overrides`
    | adjusts any type, a package's included, after its definition and every
    | Tags::extend() callback. Rule keys: label, hierarchical, max_depth, max,
    | curated, stable_slugs, global_only, on, private.
    |
    */

    'default_type' => 'tag',

    'types' => [],

    'overrides' => [],

    /*
    |--------------------------------------------------------------------------
    | Names, descriptions and slugs
    |--------------------------------------------------------------------------
    |
    | Lengths are per locale. The dictionary keeps C, C++ and C# three tags.
    | `unicode` keeps non-ASCII letters in slugs (CJK, Cyrillic, …); off, such
    | names are refused as unsluggable. Changing any slug setting later changes
    | how new strings are matched, not stored slugs: run
    | `php artisan sluggable:regenerate` on the tag model afterwards.
    |
    */

    'names' => [
        'max_length' => 64,
    ],

    'descriptions' => [
        'max_length' => 500,
    ],

    'slugs' => [
        'max_length' => 80,
        'separator' => '-',
        'unicode' => false,
        'dictionary' => ['+' => 'plus', '#' => 'sharp', '&' => 'and', '@' => 'at'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hierarchy, suggestions and popularity
    |--------------------------------------------------------------------------
    |
    | `hierarchy.max_depth` (1-10) caps every tree; a type asking for more is
    | clamped to it. Limits bound what a single call may return.
    |
    */

    'hierarchy' => [
        'max_depth' => 5,
    ],

    'suggest' => [
        'limit' => 10,
        'max_limit' => 50,
    ],

    'popular' => [
        'limit' => 10,
        'max_limit' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | API aliases
    |--------------------------------------------------------------------------
    |
    | The JSON API never takes a class name. `scopes` maps the aliases of
    | `?scope=alias:key` to scope models, and `taggables` maps the aliases of
    | `popular?on=alias` to taggable models.
    |
    */

    'scopes' => [],

    'taggables' => [],

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    |
    | How a tag's scope owner renders. Point it at your own JsonResource to add
    | a name or anything else your API needs; it receives the scope model.
    |
    */

    'resources' => [
        'scope' => TagScopeResource::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Gate abilities the JSON API checks. `view` (null = no check) receives the
    | type name and the scope; `manage` receives the type name and the target
    | scope (null = a global write); `scope` receives the scope model. The
    | package defines no gates: an undefined ability denies.
    |
    */

    'authorization' => [
        'abilities' => [
            'view' => null,
            'manage' => 'tags.manage',
            'scope' => 'tags.scope',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON API
    |--------------------------------------------------------------------------
    |
    | Off by default. A blank TAGS_ROUTES= keeps it off. Limits are per minute,
    | keyed by user, else by IP.
    |
    */

    'routes' => [
        'enabled' => env('TAGS_ROUTES') ?: 'off',
        'prefix' => 'api',
        'middleware' => ['api', 'auth', 'throttle:tags-api'],
        'rate_limit' => 120,
        'suggest_rate_limit' => 120,
        'max_per_page' => 100,
        'max_tree_nodes' => 1000,
    ],

];
