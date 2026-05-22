<?php

namespace Openplain\FlowField\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Openplain\FlowField\Attributes\FlowField;
use Openplain\FlowField\Support\FlowFieldCache;
use Openplain\FlowField\Support\FlowFieldCalculator;
use Openplain\FlowField\Support\FlowFieldDefinition;
use ReflectionClass;
use ReflectionMethod;

trait HasFlowFields
{
    protected static array $flowFieldRegistry = [];

    public static function bootHasFlowFields(): void
    {
        static::resolveFlowFieldDefinitions();
    }

    public function getAttribute($key)
    {
        $definitions = static::getFlowFieldDefinitions();

        if (isset($definitions[$key])) {
            return FlowFieldCache::remember($this, $definitions[$key]->getCacheKeyName(), $definitions[$key]);
        }

        return parent::getAttribute($key);
    }

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

    /**
     * @return array<string, FlowFieldDefinition>
     */
    public static function getFlowFieldDefinitions(): array
    {
        static::resolveFlowFieldDefinitions();

        return static::$flowFieldRegistry[static::class] ?? [];
    }

    public function scopeWithFlowFields(Builder $query, string ...$fields): Builder
    {
        $query->afterQuery(function ($models) use ($fields) {
            foreach ($models as $model) {
                $model->calcFlowFields(...$fields);
            }
        });

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
        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphOneOrMany) {
            $subQuery->where($relation->getMorphType(), $relation->getMorphClass());
        }

        $definition->applyWhere($subQuery);

        return $query->orderBy($subQuery, $direction);
    }

    protected function buildAggregateExpression(FlowFieldDefinition $definition): string
    {
        return match ($definition->method) {
            'sum'    => "COALESCE(SUM({$definition->column}), 0)",
            'count'  => $definition->distinct
                            ? "COUNT(DISTINCT {$definition->column})"
                            : "COUNT({$definition->column})",
            'avg'    => "AVG({$definition->column})",
            'min'    => "MIN({$definition->column})",
            'max'    => "MAX({$definition->column})",
            'exists' => "CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END",
            // Lookup ordering via correlated subquery is not supported.
            // Use a standard orderBy() on the resolved value instead.
            'lookup' => throw new \InvalidArgumentException(
                "FlowField 'lookup' does not support orderByFlowField. "
                . "Order by the related model's column directly."
            ),
            default  => throw new \InvalidArgumentException("Unsupported FlowField method: {$definition->method}"),
        };
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
}
