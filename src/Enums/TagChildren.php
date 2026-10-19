<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * What deleting a tag does to its live children.
 */
#[TranslatedLabels('tags::enums.tag_children')]
enum TagChildren: string
{
    use Helpers;

    /** Refuse while the tag has live children (the default). */
    case Refuse = 'refuse';

    /** Move the children up to the deleted tag's parent. */
    case Reparent = 'reparent';

    /** Delete the whole subtree. */
    case Delete = 'delete';
}
