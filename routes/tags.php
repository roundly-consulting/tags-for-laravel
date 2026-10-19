<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Tags\Http\Controllers\MoveTagController;
use RoundlyConsulting\Tags\Http\Controllers\PopularController;
use RoundlyConsulting\Tags\Http\Controllers\ReorderTagsController;
use RoundlyConsulting\Tags\Http\Controllers\SuggestController;
use RoundlyConsulting\Tags\Http\Controllers\TagController;
use RoundlyConsulting\Tags\Http\Controllers\TagTypeController;
use RoundlyConsulting\Tags\Support\TagsConfig;

/*
|--------------------------------------------------------------------------
| tags-for-laravel — the JSON API
|--------------------------------------------------------------------------
|
| Loaded only with `tags.routes.enabled` on. `suggest`, `popular` and
| `reorder` register before `{tag}`, so a tag with one of those slugs is
| addressed by its key.
|
*/

Route::prefix(TagsConfig::routesPrefix())
    ->middleware(TagsConfig::routesMiddleware())
    ->name('tags.')
    ->group(static function (): void {
        Route::get('tag-types', [TagTypeController::class, 'index'])->name('types.index');
        Route::get('tag-types/{type}', [TagTypeController::class, 'show'])->name('types.show');

        Route::get('tags/{type}', [TagController::class, 'index'])->name('index');
        Route::post('tags/{type}', [TagController::class, 'store'])->name('store');
        Route::get('tags/{type}/suggest', SuggestController::class)->middleware('throttle:tags-suggest')->name('suggest');
        Route::get('tags/{type}/popular', PopularController::class)->name('popular');
        Route::post('tags/{type}/reorder', ReorderTagsController::class)->name('reorder');
        Route::get('tags/{type}/{tag}', [TagController::class, 'show'])->name('show');
        Route::patch('tags/{type}/{tag}', [TagController::class, 'update'])->name('update');
        Route::delete('tags/{type}/{tag}', [TagController::class, 'destroy'])->name('destroy');
        Route::post('tags/{type}/{tag}/move', MoveTagController::class)->name('move');
        Route::post('tags/{type}/{tag}/restore', [TagController::class, 'restore'])->name('restore');
    });
