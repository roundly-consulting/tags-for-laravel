<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures;

use RoundlyConsulting\Tags\Tests\TestCase;

/**
 * A uuid install: tags and every taggable/scope keyed by uuid.
 */
abstract class UuidKeyTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [...parent::configBeforeBoot(), 'tags.primary_key_type' => 'uuid', 'tags.key_type' => 'uuid', 'tags.routes.enabled' => 'on'];
    }
}
