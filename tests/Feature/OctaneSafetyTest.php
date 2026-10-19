<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use RoundlyConsulting\Tags\Actions\Tagging\AttachTagsAction;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\TagsManager;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Article;

/*
 * Under Octane every request runs in a sandbox container cloned from the base application. The
 * manager is a singleton a consumer resolves at boot (`Tags::define()` in a provider), so it must
 * never hold on to the container it was built with.
 */

test('the_manager_resolves_actions_from_the_current_container', function (): void {
    $article = Article::query()->create();
    $manager = app(TagsManager::class);
    Tags::types(); // the facade caches its root

    $original = Container::getInstance();
    $other = new Container;
    $other->bind(AttachTagsAction::class, static fn (): never => throw new RuntimeException('current container'));

    Container::setInstance($other);

    try {
        expect(fn () => Tags::attach($article, 'live'))->toThrow(RuntimeException::class, 'current container')
            ->and(fn () => $manager->attach($article, 'live'))->toThrow(RuntimeException::class, 'current container')
            ->and($manager->container())->toBe($other);
    } finally {
        Container::setInstance($original);
    }

    expect(Tags::attach($article, 'live')->attached)->toHaveCount(1);
});
