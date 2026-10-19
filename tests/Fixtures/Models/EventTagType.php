<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

/**
 * A consumer package's types, as events will declare them.
 */
enum EventTagType: string
{
    case Category = 'events:category';
    case Label = 'events:label';
}
