<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures;

use RoundlyConsulting\Tags\Tests\TestCase;

/**
 * The JSON API on: the routes load at boot, so the switch is set before the providers boot.
 */
abstract class HttpTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [...parent::configBeforeBoot(), 'tags.routes.enabled' => 'on'];
    }
}
