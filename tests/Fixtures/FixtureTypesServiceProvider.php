<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;

/**
 * A stand-in consumer package: it declares its own types from boot(), as events will.
 */
final class FixtureTypesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Tags::define('fixture:category')
            ->label('Category')
            ->hierarchical(maxDepth: 3)
            ->single()
            ->stableSlugs()
            ->on(Article::class);

        Tags::define('fixture:label')
            ->label(['en' => 'Label', 'sk' => 'Označenie', 'de' => 'Etikett'])
            ->max(10)
            ->on(Article::class);
    }
}
