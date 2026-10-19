<?php

declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * S-7: controllers read only validated input — never `->all()`, `->input()`, `->get()` or
 * `->query()` on a request.
 */
test('controllers_use_validated_input_only', function (): void {
    $violations = [];

    foreach (glob(__DIR__.'/../../src/Http/Controllers/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match_all('/\$request->(all|input|get|query|post|only|except|json|string|integer|boolean|collect)\(/', $source, $matches) > 0) {
            $violations[] = basename($file).': '.implode(', ', array_unique($matches[1]));
        }
    }

    expect(glob(__DIR__.'/../../src/Http/Controllers/*.php'))->toHaveCount(7)
        ->and($violations)->toBe([]);
});

arch('requests are form requests')
    ->expect('RoundlyConsulting\Tags\Http\Requests')
    ->toExtend(Request::class);
