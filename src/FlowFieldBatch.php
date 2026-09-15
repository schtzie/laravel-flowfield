<?php

declare(strict_types=1);

namespace Schtzie\FlowField;

use Closure;
use Schtzie\FlowField\Support\FlowFieldCache;

class FlowFieldBatch
{
    /**
     * Are we currently deferring invalidations?
     */
    protected static bool $isDeferring = false;

    /**
     * Map of deferred invalidations: [targetClass => [parentId => parentId]]
     *
     * @var array<class-string<\Illuminate\Database\Eloquent\Model>, array<int|string, int|string>>
     */
    protected static array $deferredInvalidations = [];

    /**
     * Execute a callback while deferring all FlowField cache invalidations.
     * When the callback completes, all unique targets are invalidated exactly once.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function defer(Closure $callback): mixed
    {
        if (self::$isDeferring) {
            // Already inside a batch, just run the callback
            return $callback();
        }

        self::$isDeferring = true;
        self::$deferredInvalidations = [];

        try {
            $result = $callback();

            // Process all deferred invalidations uniquely
            foreach (self::$deferredInvalidations as $targetClass => $ids) {
                foreach ($ids as $parentId) {
                    self::executeInvalidation($targetClass, $parentId);
                }
            }

            return $result;
        } finally {
            self::$isDeferring = false;
            self::$deferredInvalidations = [];
        }
    }

    /**
     * Record a cache invalidation target to be executed later.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $targetClass
     */
    public static function recordInvalidation(string $targetClass, int|string $parentId): void
    {
        self::$deferredInvalidations[$targetClass][$parentId] = $parentId;
    }

    /**
     * Check if we are currently inside a defer block.
     */
    public static function isDeferring(): bool
    {
        return self::$isDeferring;
    }

    /**
     * Execute a single cache invalidation and optionally warm it.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $targetClass
     */
    public static function executeInvalidation(string $targetClass, int|string $parentId): void
    {
        FlowFieldCache::invalidateAll($targetClass, $parentId);

        if (config('flowfield.auto_warm', false)) {
            $parent = $targetClass::find($parentId);
            if ($parent) {
                FlowFieldCache::warm($parent);
            }
        }
    }
}
