<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures;

use RoundlyConsulting\Tags\Tests\TestCase;

/**
 * A ulid install: tags and every taggable/scope keyed by ulid.
 */
abstract class UlidKeyTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [...parent::configBeforeBoot(), 'tags.primary_key_type' => 'ulid', 'tags.key_type' => 'ulid', 'tags.routes.enabled' => 'on'];
    }
}
