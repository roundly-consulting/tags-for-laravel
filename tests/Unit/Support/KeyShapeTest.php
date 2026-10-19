<?php

declare(strict_types=1);

use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Tags\Support\KeyShape;

test('bigint_keys_stay_within_the_signed_64_bit_range', function (mixed $key, bool $fits): void {
    expect(KeyShape::fits($key, KeyType::BigInt))->toBe($fits);
})->with([
    'an int' => [42, true],
    'digits' => ['42', true],
    '18 nines' => [str_repeat('9', 18), true],
    'the bigint max' => ['9223372036854775807', true],
    'leading zeros' => ['0000000000000000042', true],
    'the bigint max plus one' => ['9223372036854775808', false],
    '19 nines' => [str_repeat('9', 19), false],
    '20 digits' => [str_repeat('1', 20), false],
    'a sign' => ['-1', false],
]);
