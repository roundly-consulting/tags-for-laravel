<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use RoundlyConsulting\Tags\Models\TagLock;

/**
 * The lock of one tree — one (type, scope) — that every tree write takes first: creating under
 * a parent or at a position, moving, reordering, deleting with children, restoring a child.
 *
 * @internal
 */
final class TreeLock
{
    public static function lock(string $type, string $scopeKey): void
    {
        LockedRow::mutex(TagLock::query(), ['type' => $type, 'scope_key' => $scopeKey]);
    }
}
