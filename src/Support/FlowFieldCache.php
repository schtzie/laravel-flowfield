<?php

namespace Openplain\FlowField\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Openplain\FlowField\Concerns\HasFlowFields;

class FlowFieldCache
{
    protected static array $tableNameCache = [];

    protected static ?bool $usesTagsCache = null;

    private const CACHE_MISS = '__flowfield_miss__';

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
        $ttl = $ttl ?? config('flowfield.cache.ttl');
        $store = static::taggedStore($model);

        if ($ttl === null) {
            $store->forever($key, $value);
        } else {
            $store->put($key, $value, $ttl);
        }
    }

    public static function remember(Model $model, string $field, FlowFieldDefinition $definition): mixed
    {
        // ttl: 0 = no-cache mode — always calculate fresh, skip cache entirely
        if ($definition->ttl === 0) {
            return FlowFieldCalculator::calculate($model, $definition);
        }

        $key = static::buildKey($model, $field);
        $value = static::taggedStore($model)->get($key, static::CACHE_MISS);

        if ($value !== static::CACHE_MISS) {
            return $value;
        }

        $value = FlowFieldCalculator::calculate($model, $definition);

        static::put($model, $field, $value, $definition->ttl);

        return $value;
    }

    public static function invalidate(string $modelClass, int|string $id, ?string $field = null): void
    {
        if ($field !== null) {
            $key = static::buildKeyFromParts($modelClass, $id, $field);
            static::store()->forget($key);

            return;
        }

        static::invalidateAll($modelClass, $id);
    }

    public static function invalidateAll(string $modelClass, int|string $id): void
    {
        if (static::usesTags()) {
            $tag = static::buildTag($modelClass, $id);
            Cache::store(static::storeName())->tags([$tag])->flush();

            return;
        }

        if (! in_array(HasFlowFields::class, class_uses_recursive($modelClass))) {
            return;
        }

        $definitions = (new $modelClass)->getFlowFieldDefinitions();

        foreach ($definitions as $definition) {
            $key = static::buildKeyFromParts($modelClass, $id, $definition->getCacheKeyName());
            static::store()->forget($key);
        }
    }

    public static function warm(Model $model, ?array $fields = null): void
    {
        if (! in_array(HasFlowFields::class, class_uses_recursive($model))) {
            return;
        }

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
    }

    public static function buildKey(Model $model, string $field): string
    {
        return static::buildKeyFromParts(get_class($model), $model->getKey(), $field);
    }

    public static function buildKeyFromParts(string $modelClass, int|string $id, string $field): string
    {
        $prefix = config('flowfield.cache.prefix', 'flowfield');
        $table = static::resolveTableName($modelClass);

        return "{$prefix}:{$table}:{$id}:{$field}";
    }

    public static function buildTag(string $modelClass, int|string $id): string
    {
        $prefix = config('flowfield.cache.prefix', 'flowfield');
        $table = static::resolveTableName($modelClass);

        return "{$prefix}:{$table}:{$id}";
    }

    protected static function resolveTableName(string $modelClass): string
    {
        return static::$tableNameCache[$modelClass] ??= (new $modelClass)->getTable();
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
        return config('flowfield.cache.store');
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
        } catch (\Exception $e) {
            return static::$usesTagsCache = false;
        }
    }
}
