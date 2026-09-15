<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build the canonical FlowField cache key for use in test assertions.
 */
function cacheKey(string $table, int|string $id, string $field): string
{
    $prefix = config('flowfield.cache.prefix', 'flowfield');

    return "{$prefix}:{$table}:{$id}:{$field}";
}

// ---------------------------------------------------------------------------
// Custom expectations
// ---------------------------------------------------------------------------

expect()->extend('toBeFlowFieldCached', function (string $table, int|string $id, string $field) {
    $key = cacheKey($table, $id, $field);
    $store = config('flowfield.cache.store');

    expect(Cache::store($store)->get($key))->not->toBeNull(
        "Expected FlowField '{$field}' for {$table}:{$id} to be cached, but it was not."
    );

    return $this;
});

expect()->extend('toNotBeFlowFieldCached', function (string $table, int|string $id, string $field) {
    $key = cacheKey($table, $id, $field);
    $store = config('flowfield.cache.store');

    expect(Cache::store($store)->get($key))->toBeNull(
        "Expected FlowField '{$field}' for {$table}:{$id} to NOT be cached, but it was."
    );

    return $this;
});
