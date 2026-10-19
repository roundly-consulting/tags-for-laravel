<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures;

use RoundlyConsulting\Tags\Tests\Fixtures\Models\CustomTag;
use RoundlyConsulting\Tags\Tests\TestCase;

/**
 * The tag model swapped for a host subclass before boot.
 */
abstract class SwappedTagTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->swapModel('tags.models.tag', CustomTag::class);
    }
}
