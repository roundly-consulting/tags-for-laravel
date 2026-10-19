<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

/**
 * What `Tags::repair()` fixes: tree paths and depths, scope keys, usage counts, and taggings
 * whose model or tag is gone (`orphans`) — optionally for one type, optionally as a dry run.
 */
final readonly class RepairOptions
{
    public function __construct(
        public bool $paths = true,
        public bool $scopeKeys = true,
        public bool $counts = true,
        public bool $orphans = true,
        public ?string $type = null,
        public bool $dryRun = false,
    ) {}
}
