<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\DataTransferObjects;

use RoundlyConsulting\Tags\Enums\FindingSeverity;

/**
 * One `tags:doctor` finding.
 */
final readonly class DoctorFinding
{
    public function __construct(
        public FindingSeverity $severity,
        public string $section,
        public string $message,
    ) {}
}
