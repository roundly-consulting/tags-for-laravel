<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * The README's ```php blocks, verbatim: the taggable model and the usage example.
 *
 * @return list<string>
 */
function tagsReadmeBlocks(): array
{
    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents(__DIR__.'/../../README.md'), $matches);

    return $matches[1];
}

it('ships two php blocks: the taggable model and the usage example', function (): void {
    expect(tagsReadmeBlocks())->toHaveCount(2);
});

test('readme_example_runs', function (): void {
    Schema::create('concerts', function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });

    if (! class_exists('Concert', false)) {
        eval(tagsReadmeBlocks()[0]);
    }

    $concert = Concert::query()->create();

    ob_start();

    try {
        eval(tagsReadmeBlocks()[1]);
    } finally {
        $printed = (string) ob_get_clean();
    }

    expect($printed)->toBe('JazzLive, Outdoor')
        ->and(Concert::withAnyTags(['music'], 'genre', descendants: true)->count())->toBe(1)
        ->and(Concert::withAllTags(['live', 'outdoor'], 'label')->count())->toBe(1)
        ->and(Tags::type('label')->suggest('liv')->pluck('slug')->all())->toBe(['live'])
        ->and(Tags::type('label')->popular(10)->pluck('slug')->sort()->values()->all())->toBe(['live', 'outdoor'])
        ->and(Tag::query()->where('type', 'genre')->get()->first(fn (Tag $tag): bool => $tag->slug === 'music')?->getTranslation('name', 'sk'))->toBe('Hudba')
        ->and(Tags::type('genre')->findOrFail('music')->color)->toBe('#f24e29');
});
