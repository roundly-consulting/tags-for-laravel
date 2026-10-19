<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

/**
 * What a repair found (and, unless it was a dry run, fixed).
 */
final readonly class RepairReport
{
    /**
     * @param  int  $orphans  taggings whose model is gone
     * @param  list<string>  $unresolvableTypes  taggable types whose class could not be found
     * @param  int  $trashedTaggings  taggings of deleted tags (a plain `$tag->delete()` leaves them)
     */
    public function __construct(
        public int $paths = 0,
        public int $cycles = 0,
        public int $scopeKeys = 0,
        public int $counts = 0,
        public int $orphans = 0,
        public array $unresolvableTypes = [],
        public bool $dryRun = false,
        public int $trashedTaggings = 0,
    ) {}

    public function isClean(): bool
    {
        return $this->paths === 0 && $this->cycles === 0 && $this->scopeKeys === 0 && $this->counts === 0
            && $this->orphans === 0 && $this->unresolvableTypes === [] && $this->trashedTaggings === 0;
    }
}
