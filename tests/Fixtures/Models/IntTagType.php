<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

/**
 * An int-backed enum: never a tag type.
 */
enum IntTagType: int
{
    case One = 1;
}
