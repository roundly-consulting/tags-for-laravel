<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * How serious a `tags:doctor` finding is: errors make the command fail.
 */
#[TranslatedLabels('tags::enums.finding_severity')]
enum FindingSeverity: string
{
    use Helpers;

    case Error = 'error';
    case Warning = 'warning';
}
