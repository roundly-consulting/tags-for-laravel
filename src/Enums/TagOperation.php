<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Every mutating verb of the public API, as the fake records it.
 */
#[TranslatedLabels('tags::enums.tag_operation')]
enum TagOperation: string
{
    use Helpers;

    case Attach = 'attach';
    case Detach = 'detach';
    case Sync = 'sync';
    case Clear = 'clear';
    case CreateTag = 'create_tag';
    case FindOrCreateTag = 'find_or_create_tag';
    case UpdateTag = 'update_tag';
    case MoveTag = 'move_tag';
    case ReorderTags = 'reorder_tags';
    case DeleteTag = 'delete_tag';
    case RestoreTag = 'restore_tag';
    case Repair = 'repair';
}
