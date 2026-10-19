<?php

declare(strict_types=1);

use RoundlyConsulting\Tags\Models\Tag;

it('declares the tag model swappable through its config key', function (): void {
    expect(Tag::class)->toBeSwappableVia('tags.models.tag');
});
