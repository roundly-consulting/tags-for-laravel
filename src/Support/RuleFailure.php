<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Support;

use Illuminate\Support\Str;

/**
 * Why `TagsRule` refused a value, and the first entry that failed, as the client gave it.
 *
 * @internal
 */
final readonly class RuleFailure
{
    private const int SHOWN = 64;

    public function __construct(
        public string $reason,
        public string $entry,
    ) {}

    public static function invalid(mixed $entry): self
    {
        return new self('invalid', self::show($entry));
    }

    public static function unknown(mixed $entry): self
    {
        return new self('not_found', self::show($entry));
    }

    /**
     * The entry as text, cut to a readable length.
     */
    private static function show(mixed $entry): string
    {
        $text = is_string($entry) || is_int($entry) ? (string) $entry : (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Str::limit($text, self::SHOWN);
    }
}
