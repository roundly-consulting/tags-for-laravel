<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

/**
 * Both directions: every `tags.*` key the code reads is shipped, and every shipped leaf is read.
 * `src/` plus the migrations (they read the key types and table names). The slug dictionary is
 * read as a whole map; its shipped entries are characters, not keys the package names.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/tags.php')->toSatisfyConfigContract(
        [__DIR__.'/../../src', __DIR__.'/../../database/migrations'],
        ['allowUnread' => [
            'tags.slugs.dictionary.+',
            'tags.slugs.dictionary.#',
            'tags.slugs.dictionary.&',
            'tags.slugs.dictionary.@',
        ]],
    );
});

it('never claims a config handle Laravel ships itself', function (): void {
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    expect($framework.'/config/app.php')->toBeFile()
        ->and($framework.'/config/tags.php')->not->toBeFile();
});
