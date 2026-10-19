<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use RoundlyConsulting\Tags\Support\NotGiven;

/**
 * Changes to a tag. Every field left as {@see NotGiven} stays; `null` clears a description,
 * colour or icon. A name or description map merges into the stored one per locale.
 */
final readonly class UpdateTagData
{
    /**
     * @param  NotGiven|string|array<string, string>  $name
     * @param  NotGiven|string|array<string, string>|null  $description
     * @param  NotGiven|string|array<string, string>  $slug
     */
    public function __construct(
        public NotGiven|string|array $name = new NotGiven,
        public NotGiven|string|array|null $description = new NotGiven,
        public NotGiven|string|array $slug = new NotGiven,
        public NotGiven|string|null $color = new NotGiven,
        public NotGiven|string|null $icon = new NotGiven,
    ) {}
}
