<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use Schtzie\FlowField\Attributes\FlowField;
use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Support\FlowFieldCalculator;
use Schtzie\FlowField\Support\FlowFieldDefinition;
use Throwable;

trait HasFlowFields
{
    /**
     * Registered FlowField definitions per model class.
     * Safe to keep across Octane requests — PHP attributes are immutable.
     */
    protected static array $flowFieldRegistry = [];

    /**
     * Per-instance runtime FlowFilter values.
     *
     * Structure: [field_name => [filter_key => filter_value]]
     */
    protected array $flowFilterValues = [];

    // =========================================================================
    // Boot
    // =========================================================================

    public static function bootHasFlowFields(): void
    {
        static::resolveFlowFieldDefinitions();
    }

    /**
     * @return array<string, FlowFieldDefinition>
     */
    public static function getFlowFieldDefinitions(): array
    {
        static::resolveFlowFieldDefinitions();

        return static::$flowFieldRegistry[static::class] ?? [];
    }

    /**
     * Batch calculate FlowFields for a collection in N queries (one per field).
     * Uses GROUP BY to aggregate all parent IDs in a single query per field.
     *
     * @param  array<Model>  $models
     */
    public static function batchCalcFlowFields(array $models, string ...$fields): void
    {
        if (empty($models)) {
            return;
        }

        $definitions = static::getFlowFieldDefinitions();

        if (empty($fields)) {
            $fields = array_keys($definitions);
        }

        $chunkSize = config('flowfield.batch.chunk_size', 500);
        $parent = new static;
        $localKey = $parent->getKeyName();
        $ids = array_map(fn ($m) => $m->getKey(), $models);
        $modelMap = [];
        foreach ($models as $model) {
            $modelMap[$model->getKey()] = $model;
        }

        foreach ($fields as $field) {
            if (! isset($definitions[$field])) {
                continue;
            }

            $definition = $definitions[$field];

            // Only standard aggregate methods support GROUP BY batch, and dynamic parameters
            // require the parent instance so they must be calculated per-model.
            if (! in_array($definition->method, ['sum', 'count', 'avg', 'wavg', 'min', 'max', 'exists'], true) || $definition->hasDynamicParameters()) {
                // Fall back to per-model calculation
                foreach ($models as $model) {
                    $value = FlowFieldCalculator::calculate($model, $definition);
                    FlowFieldCache::put($model, $definition->getCacheKeyName(), $value, $definition->ttl);
                }

                continue;
            }

            try {
                $relation = $parent->{$definition->relation}();
                $related = $relation->getRelated();
                $foreignKey = $relation->getForeignKeyName();
                $aggExpr = $parent->buildAggregateExpression($definition);

                // GROUP BY batch in chunks to respect memory limits
                foreach (array_chunk($ids, $chunkSize) as $chunk) {
                    $rows = $related->newQuery()
                        ->selectRaw("{$foreignKey} as __parent_id, {$aggExpr} as __agg_value")
                        ->whereIn($foreignKey, $chunk);

                    if ($relation instanceof MorphOneOrMany) {
                        $rows->where($relation->getMorphType(), $relation->getMorphClass());
                    }

                    $definition->applyWhere($rows);

                    $results = $rows->groupBy($foreignKey)->get();

                    // Map results back to models and write cache
                    $resultMap = $results->keyBy('__parent_id');

                    foreach ($chunk as $id) {
                        $model = $modelMap[$id] ?? null;
                        if (! $model) {
                            continue;
                        }

                        $row = $resultMap->get($id);
                        $value = $row ? $row->__agg_value : ($definition->method === 'exists' ? false : 0);

                        if ($definition->method === 'exists') {
                            $value = (bool) $value;
                        }

                        FlowFieldCache::put($model, $definition->getCacheKeyName(), $value, $definition->ttl);
                    }
                }
            } catch (Throwable) {
                // Fall back to per-model calculation if relation type unsupported
                foreach ($models as $model) {
                    $value = FlowFieldCalculator::calculate($model, $definition);
                    FlowFieldCache::put($model, $definition->getCacheKeyName(), $value, $definition->ttl);
                }
            }
        }
    }

    // =========================================================================
    // Attribute access
    // =========================================================================

    public function getAttribute($key)
    {
        $definitions = static::getFlowFieldDefinitions();

        if (isset($definitions[$key])) {
            return FlowFieldCache::remember(
                $this,
                $definitions[$key]->getCacheKeyName(),
                $definitions[$key],
                $this->flowFilterValues[$key] ?? []
            );
        }

        return parent::getAttribute($key);
    }

    // =========================================================================
    // FlowFilters — runtime-scoped aggregation
    // =========================================================================

    /**
     * Set a runtime FlowFilter for a specific FlowField or all FlowFields.
     *
     * @param  string  $filterKey  The column to filter (e.g. 'posting_date')
     * @param  mixed  $value  Scalar, array, or [from, to] range
     * @param  string|null  $field  Limit to a specific FlowField (null = all)
     */
    public function setFlowFilter(string $filterKey, mixed $value, ?string $field = null): static
    {
        $definitions = static::getFlowFieldDefinitions();

        foreach ($definitions as $name => $definition) {
            if ($field !== null && $name !== $field) {
                continue;
            }

            // Apply the filter regardless of whether the field declared flowFilters.
            // The flowFilters array on the attribute is purely descriptive / for
            // documentation — it does not gate which columns can be filtered at runtime.
            $this->flowFilterValues[$name][$filterKey] = $value;
        }

        return $this;
    }

    /**
     * Fluent one-off filter — returns $this so you can chain -> access.
     */
    public function withFlowFilter(string $filterKey, mixed $value, ?string $field = null): static
    {
        return $this->setFlowFilter($filterKey, $value, $field);
    }

    /**
     * Clear all runtime FlowFilters, or just those for a specific field.
     */
    public function clearFlowFilters(?string $field = null): static
    {
        if ($field === null) {
            $this->flowFilterValues = [];
        } else {
            unset($this->flowFilterValues[$field]);
        }

        return $this;
    }

    // =========================================================================
    // Standard FlowField operations
    // =========================================================================

    public function calcFlowFields(string ...$fields): static
    {
        $definitions = static::getFlowFieldDefinitions();

        if (empty($fields)) {
            $fields = array_keys($definitions);
        }

        foreach ($fields as $field) {
            if (isset($definitions[$field])) {
                $definition = $definitions[$field];
                $value = FlowFieldCalculator::calculate($this, $definition);
                FlowFieldCache::put($this, $definition->getCacheKeyName(), $value, $definition->ttl);
            }
        }

        return $this;
    }

    public function flushFlowFields(string ...$fields): static
    {
        $definitions = static::getFlowFieldDefinitions();

        if (empty($fields)) {
            $fields = array_keys($definitions);
        }

        foreach ($fields as $field) {
            if (isset($definitions[$field])) {
                FlowFieldCache::invalidate(static::class, $this->getKey(), $definitions[$field]->getCacheKeyName());
            }
        }

        return $this;
    }

    /**
     * Return all (or specified) FlowField values as an associative array.
     *
     * Useful for API serialization, audit logging, or comparing field sets
     * without individually accessing each attribute.
     *
     * Cache behaviour is identical to normal attribute access:
     * values are served from cache when warm, calculated on miss.
     *
     * @return array<string, mixed>
     */
    public function getFlowFieldValues(string ...$fields): array
    {
        $definitions = static::getFlowFieldDefinitions();

        if (empty($fields)) {
            $fields = array_keys($definitions);
        }

        $values = [];

        foreach ($fields as $field) {
            if (isset($definitions[$field])) {
                $values[$field] = $this->getAttribute($field);
            }
        }

        return $values;
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Warm FlowField caches for every model in the result set (1 query per field).
     * Uses calcFlowFields() per model — consider withFlowFieldsBatch() for large sets.
     */
    public function scopeWithFlowFields(Builder $query, string ...$fields): Builder
    {
        if (method_exists($query, 'afterQuery')) {
            $query->afterQuery(function ($models) use ($fields) {
                foreach ($models as $model) {
                    $model->calcFlowFields(...$fields);
                }
            });

            return $query;
        }

        $modelClass = get_class($query->getModel());
        $modelClass::retrieved(function ($model) use ($fields) {
            $model->calcFlowFields(...$fields);
        });

        return $query;
    }

    /**
     * N+1 Prevention: load FlowFields for a collection using one GROUP BY
     * query per field instead of N individual queries per model.
     *
     * Usage:
     *   Customer::withFlowFieldsBatch('balance', 'entry_count')->get();
     *
     * Behaviour:
     *   - One SELECT ... GROUP BY query per field (not per model).
     *   - Results are stored in cache immediately.
     *   - Respects chunk_size config for very large collections.
     */
    public function scopeWithFlowFieldsBatch(Builder $query, string ...$fields): Builder
    {
        if (method_exists($query, 'afterQuery')) {
            $query->afterQuery(function ($models) use ($fields) {
                if ($models->isEmpty()) {
                    return;
                }

                static::batchCalcFlowFields($models->all(), ...$fields);
            });
        }

        return $query;
    }

    /**
     * N+1 Prevention: eager load FlowFields as correlated subqueries on the
     * main SELECT, eliminating per-model queries. Values are populated into
     * the model attributes and written to cache.
     *
     * Best for paginated result sets where GROUP BY is not practical.
     */
    public function scopeWithFlowFieldSubqueries(Builder $query, string ...$fields): Builder
    {
        $definitions = static::getFlowFieldDefinitions();

        if (empty($fields)) {
            $fields = array_keys($definitions);
        }

        $parent = new static;

        $baseColumnsAdded = false;

        foreach ($fields as $field) {
            if (! isset($definitions[$field])) {
                continue;
            }

            $definition = $definitions[$field];

            // Only standard aggregate methods can be expressed as subqueries, and dynamic parameters
            // require the parent instance so they must be calculated per-model.
            if (! in_array($definition->method, ['sum', 'count', 'avg', 'wavg', 'min', 'max', 'exists'], true) || $definition->hasDynamicParameters()) {
                continue;
            }

            try {
                $relation = $parent->{$definition->relation}();
                $related = $relation->getRelated();
                $foreignKey = $relation->getForeignKeyName();
                $localKey = $relation->getLocalKeyName();
                $aggExpr = $parent->buildAggregateExpression($definition);

                $subQuery = $related->newQuery()
                    ->selectRaw($aggExpr)
                    ->whereColumn("{$related->getTable()}.{$foreignKey}", "{$parent->getTable()}.{$localKey}");

                if ($relation instanceof MorphOneOrMany) {
                    $subQuery->where($relation->getMorphType(), $relation->getMorphClass());
                }

                $definition->applyWhere($subQuery);

                // Ensure base model columns are not lost when selectSub is called.
                // Laravel's addSelect() drops the implicit "SELECT *" so we must
                // explicitly restore it before adding any computed columns.
                if (! $baseColumnsAdded) {
                    $query->addSelect("{$parent->getTable()}.*");
                    $baseColumnsAdded = true;
                }

                $query->selectSub($subQuery, "ff_{$field}");
            } catch (Throwable) {
                // Skip unsupported relations gracefully
                continue;
            }
        }

        // After results load, copy subquery values into cache
        if (method_exists($query, 'afterQuery')) {
            $query->afterQuery(function ($models) use ($fields, $definitions) {
                foreach ($models as $model) {
                    // Skip models without a persisted primary key
                    if ($model->getKey() === null) {
                        continue;
                    }

                    foreach ($fields as $field) {
                        if (! isset($definitions[$field])) {
                            continue;
                        }
                        $ffKey = "ff_{$field}";
                        // Use getAttribute() to read the dynamically-selected column
                        $raw = $model->getAttribute($ffKey);
                        if ($raw !== null) {
                            $value = $definitions[$field]->method === 'exists'
                                ? (bool) $raw
                                : $raw;
                            FlowFieldCache::put($model, $definitions[$field]->getCacheKeyName(), $value, $definitions[$field]->ttl);
                        }
                    }
                }
            });
        }

        return $query;
    }

    public function scopeOrderByFlowField(Builder $query, string $field, string $direction = 'asc'): Builder
    {
        $definitions = static::getFlowFieldDefinitions();

        if (! isset($definitions[$field])) {
            return $query;
        }

        $definition = $definitions[$field];
        $parent = new static;
        $relation = $parent->{$definition->relation}();
        $related = $relation->getRelated();
        $foreignKey = $relation->getForeignKeyName();
        $localKey = $relation->getLocalKeyName();

        $subQuery = $related->newQuery()
            ->selectRaw($this->buildAggregateExpression($definition))
            ->whereColumn("{$related->getTable()}.{$foreignKey}", "{$parent->getTable()}.{$localKey}");

        // For polymorphic relations (morphMany / morphOne), the correlated subquery
        // must also constrain on the morph-type column. Without this, the aggregate
        // spans ALL morph parent types — e.g. Post comments and Video comments would
        // be mixed together when ordering posts by comment_count.
        if ($relation instanceof MorphOneOrMany) {
            $subQuery->where($relation->getMorphType(), $relation->getMorphClass());
        }

        $definition->applyWhere($subQuery);

        return $query->orderBy($subQuery, $direction);
    }

    protected static function resolveFlowFieldDefinitions(): void
    {
        if (isset(static::$flowFieldRegistry[static::class])) {
            return;
        }

        static::$flowFieldRegistry[static::class] = [];

        $reflection = new ReflectionClass(static::class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(FlowField::class);

            foreach ($attributes as $attribute) {
                $snakeName = Str::snake($method->getName());

                static::$flowFieldRegistry[static::class][$snakeName] = FlowFieldDefinition::fromAttribute(
                    $snakeName,
                    $attribute->newInstance(),
                );
            }
        }
    }

    protected function buildAggregateExpression(FlowFieldDefinition $definition): string
    {
        return match ($definition->method) {
            'sum' => "COALESCE(SUM({$definition->column}), 0)",
            'count' => $definition->distinct
                                ? "COUNT(DISTINCT {$definition->column})"
                                : "COUNT({$definition->column})",
            'avg' => "AVG({$definition->column})",
            'wavg' => "COALESCE(SUM({$definition->column} * {$definition->weightColumn}) / NULLIF(SUM({$definition->weightColumn}), 0), 0)",
            'min' => "MIN({$definition->column})",
            'max' => "MAX({$definition->column})",
            'exists' => 'CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END',
            'expression' => "({$definition->expression})",
            // Lookup ordering via correlated subquery is not supported.
            // Use a standard orderBy() on the resolved value instead.
            'lookup' => throw new InvalidArgumentException(
                "FlowField 'lookup' does not support orderByFlowField. "
                ."Order by the related model's column directly."
            ),
            default => throw new InvalidArgumentException("Unsupported FlowField method: {$definition->method}"),
        };
    }
}
