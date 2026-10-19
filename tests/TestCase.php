<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\QueryBuilder\QueryBuilderServiceProvider;
use RoundlyConsulting\Sluggable\SluggableServiceProvider;
use RoundlyConsulting\Tags\TagsServiceProvider;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Note;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Translatable\TranslatableServiceProvider;

abstract class TestCase extends PackageTestCase
{
    /**
     * Every provider tags requires: the migrations use translatable's and sluggable's schema
     * macros and sluggable's per-locale index DDL.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [
            SluggableServiceProvider::class,
            TranslatableServiceProvider::class,
            QueryBuilderServiceProvider::class,
            TagsServiceProvider::class,
        ];
    }

    /**
     * Publish-only migrations run the way a host runs them after publishing: by provider class.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            TagsServiceProvider::class,
            __DIR__.'/Fixtures/migrations',
        ];
    }

    /**
     * Migrate once per schema signature: every tags migration runs per-locale slug index DDL,
     * which would otherwise dominate the real-engine legs. Racers end with SIGKILL.
     */
    protected function keepsMigratedSchema(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            'translatable.locales' => ['en', 'sk'],
            'translatable.fallback_locale' => 'en',
            'tags.scopes' => ['team' => Team::class],
            'tags.taggables' => ['article' => Article::class, 'note' => Note::class],
        ];
    }
}
