<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Where a tag type's definition comes from.
 */
#[TranslatedLabels('tags::enums.type_source')]
enum TypeSource: string
{
    use Helpers;

    /** `Tags::define()` in a service provider. */
    case Code = 'code';

    /** An entry of `tags.types`. */
    case Config = 'config';

    /** The implicit default type nobody defined. */
    case Default = 'default';
}
