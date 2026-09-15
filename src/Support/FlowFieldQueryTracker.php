<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Support;

/**
 * Per-request in-memory deduplication map for FlowField queries.
 *
 * Prevents duplicate DB queries when the same FlowField is accessed multiple
 * times within a single request lifecycle (e.g. via two different code paths).
 *
 * This is a static store — in Octane environments it is reset by
 * OctaneFlowFieldListener on every RequestReceived event.
 */
class FlowFieldQueryTracker
{
    /**
     * In-memory map of resolved FlowField values for the current request.
     *
     * Structure: [cache_key => value]
     *
     * @var array<string, mixed>
     */
    protected static array $resolved = [];

    /**
     * Check if a value has already been resolved this request.
     */
    public static function has(string $cacheKey): bool
    {
        return array_key_exists($cacheKey, static::$resolved);
    }

    /**
     * Retrieve a resolved value.
     */
    public static function get(string $cacheKey): mixed
    {
        return static::$resolved[$cacheKey] ?? null;
    }

    /**
     * Store a resolved value for the duration of the request.
     */
    public static function set(string $cacheKey, mixed $value): void
    {
        if (config('flowfield.query_deduplication.enabled', false)) {
            static::$resolved[$cacheKey] = $value;
        }
    }

    /**
     * Reset the tracker. Called by OctaneFlowFieldListener on request end.
     */
    public static function reset(): void
    {
        static::$resolved = [];
    }

    /**
     * Return all currently tracked keys (for debugging / testing).
     *
     * @return array<string>
     */
    public static function keys(): array
    {
        return array_keys(static::$resolved);
    }
}
