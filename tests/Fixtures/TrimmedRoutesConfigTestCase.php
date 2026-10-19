<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures;

use RoundlyConsulting\Tags\Tests\TestCase;

/**
 * A host whose published `config/tags.php` sets only the part of `routes` it changes. The config
 * merge is shallow, so `routes.enabled` is gone from the merged config.
 */
abstract class TrimmedRoutesConfigTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [...parent::configBeforeBoot(), 'tags.routes' => ['prefix' => 'admin-api']];
    }
}
