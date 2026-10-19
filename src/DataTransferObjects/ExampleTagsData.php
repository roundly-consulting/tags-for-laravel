<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use RoundlyConsulting\Tags\Actions\ExampleTagsAction;

/**
 * REPLACE ME — the input of {@see ExampleTagsAction}.
 *
 * Actions take a DTO, never a shape array. Rename this alongside the action it feeds.
 */
final readonly class ExampleTagsData
{
    public function __construct(
        public string $name,
    ) {}
}
