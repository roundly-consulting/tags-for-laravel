<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Tests\Fixtures\Models\Note;
use RoundlyConsulting\Tags\Types\TagTypeBuilder;

/**
 * The host side: widens the consumer's label type to its own model and caps it.
 */
final class HostExtendServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Tags::extend('fixture:label', static fn (TagTypeBuilder $type): TagTypeBuilder => $type->on(Note::class)->max(3));
    }
}
