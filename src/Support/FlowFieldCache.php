<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Support;

use DateInterval;
use DateTimeInterface;
use Exception;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Concerns\HasFlowFields;

class FlowFieldCache
{
    private const CACHE_MISS = '__flowfield_miss__';

    /** @var array<string, string> */
    protected static array $tableNameCache = [];

    protected static ?bool $usesTagsCache = null;

    /**
     * Per-request in-memory L1 cache for Octane environments.
     * Structure: [cache_key => value]
     *
     * @var array<string, mixed>
     */
    protected static array $octaneL1 = [];

    // =========================================================================
    // Core read/write operations
    // =========================================================================

    public static function get(Model $model, string $field): mixed
    {
        $key = static::buildKey($model, $field);

        return static::taggedStore($model)->get($key);
    }

    public static function put(Model $model, string $field, mixed $value, ?int $ttl = null): void
    {
        // ttl: 0 = no-cache mode — never store, always compute fresh
        if ($ttl === 0) {
            return;
        }

        $key = static::buildKey($model, $field);
        /** @var DateInterval|DateTimeInterface|int|null $configTtl */
        $configTtl = config('flowfield.cache.ttl');
        $ttl = $ttl ?? $configTtl;
        $store = static::taggedStore($model);

        if ($ttl === null) {
            $store->forever($key, $value);
        } else {
            $store->put($key, $value, $ttl);
        }

        // Also populate the Octane L1 cache if enabled
        if (static::octaneL1Enabled()) {
            static::$octaneL1[$key] = $value;
        }
    }

    /**
     * Remember: check cache (L1 → L2) and calculate on miss.
     *
     * @param  array<string, mixed>  $flowFilters  Runtime FlowFilter values for scoping
     */
    public static function remember(
        Model $model,
        string $field,
        FlowFieldDefinition $definition,
        array $flowFilters = []
    ): mixed {
        // ttl: 0 = no-cache mode — always calculate fresh, skip cache entirely
        if ($definition->ttl === 0) {
            return FlowFieldCalculator::calculate($model, $definition);
        }

        // Build a filter-aware cache key
        $key = static::buildFilterAwareKey($model, $field, $flowFilters);

        // --- L1: Octane in-memory cache ---
        if (static::octaneL1Enabled() && array_key_exists($key, static::$octaneL1)) {
            return static::$octaneL1[$key];
        }

        // --- L2: query deduplication (cross-service within request) ---
        if (FlowFieldQueryTracker::has($key)) {
            return FlowFieldQueryTracker::get($key);
        }

        // --- L3: persistent cache store ---
        $value = static::taggedStore($model)->get($key, self::CACHE_MISS);

        if ($value !== self::CACHE_MISS) {
            FlowFieldQueryTracker::set($key, $value);

            return $value;
        }

        // --- Cache miss: calculate from DB ---
        // If we have flow filters, use the filtered calculation path
        if (! empty($flowFilters)) {
            $value = static::calculateWithFilters($model, $definition, $flowFilters);
        } else {
            $value = FlowFieldCalculator::calculate($model, $definition);
        }

        // Persist to L2 store (L1 populated via put()).
        // Skip when ttl === 0 (no-cache mode, already handled at top of method).
        // ttl: null → cache forever; positive int → cache with TTL.
        $shouldCache = ! is_int($definition->ttl) || $definition->ttl > 0;

        if ($shouldCache) {
            static::put($model, $field, $value, $definition->ttl);
            // For filter-aware keys we write manually since put() uses the plain key
            if (! empty($flowFilters)) {
                /** @var DateInterval|DateTimeInterface|int|null $configTtl */
                $configTtl = config('flowfield.cache.ttl');
                $ttl = $definition->ttl ?? $configTtl;
                $store = static::taggedStore($model);
                if ($ttl === null) {
                    $store->forever($key, $value);
                } else {
                    $store->put($key, $value, $ttl);
                }
            }
        }

        FlowFieldQueryTracker::set($key, $value);

        return $value;
    }

    // =========================================================================
    // Invalidation
    // =========================================================================

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function invalidate(string $modelClass, int|string $id, ?string $field = null): void
    {
        if ($field !== null) {
            $key = static::buildKeyFromParts($modelClass, $id, $field);
            static::store()->forget($key);

            // Also purge from L1
            unset(static::$octaneL1[$key]);

            return;
        }

        static::invalidateAll($modelClass, $id);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function invalidateAll(string $modelClass, int|string $id): void
    {
        if (static::usesTags()) {
            $tag = static::buildTag($modelClass, $id);
            Cache::store(static::storeName())->tags([$tag])->flush();

            // Purge all L1 entries for this model instance
            $prefix = (string) config('flowfield.cache.prefix', 'flowfield');
            $table = static::resolveTableName($modelClass);
            $keyPrefix = "{$prefix}:{$table}:{$id}:";
            foreach (array_keys(static::$octaneL1) as $key) {
                if (str_starts_with($key, $keyPrefix)) {
                    unset(static::$octaneL1[$key]);
                }
            }

            return;
        }

        if (! in_array(HasFlowFields::class, class_uses_recursive($modelClass))) {
            return;
        }

        /** @var Model $instance */
        $instance = new $modelClass;
        if (! method_exists($instance, 'getFlowFieldDefinitions')) {
            return;
        }
        /** @var array<string, FlowFieldDefinition> $definitions */
        $definitions = $instance->getFlowFieldDefinitions();

        foreach ($definitions as $definition) {
            $key = static::buildKeyFromParts($modelClass, $id, $definition->getCacheKeyName());
            static::store()->forget($key);
            unset(static::$octaneL1[$key]);
        }
    }

    // =========================================================================
    // Warm
    // =========================================================================

    /**
     * @param  array<string>|null  $fields
     */
    public static function warm(Model $model, ?array $fields = null): void
    {
        if (! method_exists($model, 'getFlowFieldDefinitions')) {
            return;
        }

        /** @var array<string, FlowFieldDefinition> $definitions */
        $definitions = $model->getFlowFieldDefinitions();

        foreach ($definitions as $definition) {
            if ($fields !== null && ! in_array($definition->name, $fields)) {
                continue;
            }

            $value = FlowFieldCalculator::calculate($model, $definition);
            static::put($model, $definition->getCacheKeyName(), $value, $definition->ttl);
        }
    }

    public static function flush(): void
    {
        static::store()->flush();
        static::$octaneL1 = [];
    }

    // =========================================================================
    // Key builders
    // =========================================================================

    public static function buildKey(Model $model, string $field): string
    {
        return static::buildKeyFromParts(get_class($model), $model->getKey(), $field);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function buildKeyFromParts(string $modelClass, int|string $id, string $field): string
    {
        $prefix = (string) config('flowfield.cache.prefix', 'flowfield');
        $table = static::resolveTableName($modelClass);

        return "{$prefix}:{$table}:{$id}:{$field}";
    }

    /**
     * Build a cache key that incorporates FlowFilter values.
     *
     * Filtered keys use the format:
     *   flowfield:table:id:field:filter1=value1:filter2=from..to
     *
     * @param  array<string, mixed>  $filters
     */
    public static function buildFilterAwareKey(Model $model, string $field, array $filters = []): string
    {
        $base = static::buildKey($model, $field);

        if (empty($filters)) {
            return $base;
        }

        $parts = [];
        foreach ($filters as $key => $value) {
            if (is_array($value) && count($value) === 2) {
                /** @var array{scalar, scalar} $value */
                $parts[] = "{$key}={$value[0]}..{$value[1]}";
            } elseif (is_array($value)) {
                /** @var array<scalar> $value */
                $parts[] = "{$key}=".implode(',', $value);
            } else {
                $parts[] = "{$key}={$value}";
            }
        }

        return $base.':'.implode(':', $parts);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function buildTag(string $modelClass, int|string $id): string
    {
        $prefix = (string) config('flowfield.cache.prefix', 'flowfield');
        $table = static::resolveTableName($modelClass);

        return "{$prefix}:{$table}:{$id}";
    }

    // =========================================================================
    // Static state management (Octane compatibility)
    // =========================================================================

    /**
     * Reset per-request mutable state. Called by OctaneFlowFieldListener.
     *
     * Resets: usesTagsCache, tableNameCache, octaneL1.
     * Intentionally DOES NOT reset $flowFieldRegistry — PHP attribute reflection
     * is immutable and safe to share across Octane requests.
     */
    public static function resetStaticState(): void
    {
        static::$usesTagsCache = null;
        static::$tableNameCache = [];
        static::$octaneL1 = [];
        FlowFieldQueryTracker::reset();
    }

    /**
     * Full reset including the definition registry. Use in tests only.
     */
    public static function resetAll(): void
    {
        static::$usesTagsCache = null;
        static::$tableNameCache = [];
        static::$octaneL1 = [];
        FlowFieldQueryTracker::reset();
    }

    /**
     * Reset only the tag detection state (useful when switching stores in tests).
     */
    public static function resetTagsCache(): void
    {
        static::$usesTagsCache = null;
    }

    // =========================================================================
    // Octane L1 cache
    // =========================================================================

    /**
     * Check whether the Octane L1 volatile cache is enabled.
     */
    public static function octaneL1Enabled(): bool
    {
        return (bool) config('flowfield.cache.octane_l1', false);
    }

    /**
     * Calculate using the FlowFilter runtime values injected into the query.
     *
     * @param  array<string, mixed>  $flowFilters
     */
    protected static function calculateWithFilters(
        Model $model,
        FlowFieldDefinition $definition,
        array $flowFilters
    ): mixed {
        // We need to temporarily modify the calculation to apply flow filters.
        // We do this by overriding the query via a closure that wraps the relation.
        /** @var Builder<Model>|Relation<Model, Model, mixed> $query */
        $query = $model->{$definition->relation}();
        $definition->applyWhere($query);
        $definition->applyFlowFilters($query, $flowFilters);

        return match ($definition->method) {
            'sum' => $query->sum($definition->column),
            'count' => $definition->distinct
                            ? $query->distinct()->count($definition->column)
                            : $query->count($definition->column),
            'avg' => $query->avg($definition->column),
            'min' => $query->min($definition->column),
            'max' => $query->max($definition->column),
            'exists' => $query->exists(),
            'lookup' => $query->value($definition->column),
            default => FlowFieldCalculator::calculate($model, $definition),
        };
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected static function resolveTableName(string $modelClass): string
    {
        /** @var Model $instance */
        $instance = new $modelClass;

        return static::$tableNameCache[$modelClass] ??= $instance->getTable();
    }

    protected static function taggedStore(Model $model): Repository
    {
        if (static::usesTags()) {
            $tag = static::buildTag(get_class($model), $model->getKey());

            return Cache::store(static::storeName())->tags([$tag]);
        }

        return static::store();
    }

    protected static function store(): Repository
    {
        return Cache::store(static::storeName());
    }

    protected static function storeName(): ?string
    {
        $store = config('flowfield.cache.store');

        return is_string($store) ? $store : null;
    }

    protected static function usesTags(): bool
    {
        if (static::$usesTagsCache !== null) {
            return static::$usesTagsCache;
        }

        if (! config('flowfield.tag_based', true)) {
            return static::$usesTagsCache = false;
        }

        try {
            $store = Cache::store(static::storeName());

            return static::$usesTagsCache = method_exists($store->getStore(), 'tags');
        } catch (Exception $e) {
            return static::$usesTagsCache = false;
        }
    }
}
