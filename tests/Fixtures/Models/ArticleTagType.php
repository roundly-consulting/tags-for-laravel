<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Tests\Fixtures\Models;

/**
 * Host tag types as an enum.
 */
enum ArticleTagType: string
{
    case Genre = 'genre';
    case Label = 'label';
}
