<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use RoundlyConsulting\Tags\DataTransferObjects\TagKey;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * What one tag input means. A plain string is a name or slug, unless `tags.string_keys` is on
 * (uuid and ulid installs only) and it fits `tags.primary_key_type`: then it is a key, exactly as
 * if it came wrapped in a {@see TagKey}.
 *
 * @internal
 */
final class TagInputs
{
    public static function normalize(Tag|TagKey|int|string $input): Tag|TagKey|int|string
    {
        if (is_string($input) && KeyShape::isTagKey($input) && TagsConfig::stringKeys()) {
            return new TagKey($input);
        }

        return $input;
    }
}
