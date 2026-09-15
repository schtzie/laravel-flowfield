<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Schtzie\FlowField\Attributes\FlowField;

/**
 * Definition representing a single #[FlowField] attribute's compiled state.
 */
class FlowFieldDefinition
{
    /**
     * Recognized comparison operators for where conditions.
     * Used to disambiguate operator arrays from whereIn arrays.
     *
     * @var array<string>
     */
    private const OPERATORS = ['>', '<', '>=', '<=', '!=', '<>', 'like', 'between', 'not_null'];

    /**
     * @param  array<string, mixed>  $where
     * @param  string|array{string, string}|null  $ofMany
     * @param  array<string>  $expressionColumns
     * @param  array<string, array{string, string}>  $aggregates
     * @param  array<string>  $flowFilters
     * @param  string|array<string>|null  $scope
     * @param  array<string, array<string, mixed>>  $whereHas
     * @param  array{column: string, bucket: string}|null  $aging
     */
    public function __construct(
        public readonly string $name,
        public readonly string $method,
        public readonly ?string $relation,
        public readonly string $column,
        public readonly array $where,
        public readonly ?int $ttl,
        public readonly ?string $cacheKey,
        public readonly bool $distinct = false,
        public readonly string|array|null $ofMany = null,
        // --- Phase 1: Complex Aggregation ---
        public readonly ?string $expression = null,
        public readonly array $expressionColumns = [],
        public readonly array $aggregates = [],
        public readonly mixed $query = null,
        // --- FlowFilters ---
        public readonly array $flowFilters = [],
        // --- ERP Features ---
        public readonly string|array|null $scope = null,
        public readonly array $whereHas = [],
        public readonly ?array $aging = null,
        public readonly ?string $weightColumn = null,
    ) {}

    public static function fromAttribute(string $name, FlowField $attribute): self
    {
        return new self(
            name: $name,
            method: $attribute->method,
            relation: $attribute->relation,
            column: $attribute->column,
            where: $attribute->where,
            ttl: $attribute->ttl,
            cacheKey: $attribute->cacheKey,
            distinct: $attribute->distinct,
            ofMany: $attribute->ofMany,
            expression: $attribute->expression,
            expressionColumns: $attribute->expressionColumns,
            aggregates: $attribute->aggregates,
            query: $attribute->query,
            flowFilters: $attribute->flowFilters,
            scope: $attribute->scope,
            whereHas: $attribute->whereHas,
            aging: $attribute->aging,
            weightColumn: $attribute->weightColumn,
        );
    }

    public function getCacheKeyName(): string
    {
        return $this->cacheKey ?? $this->name;
    }

    /**
     * Apply all where conditions to the query.
     *
     * Supported syntaxes:
     *   ['column' => 'value']                     → WHERE column = 'value'
     *   ['column' => null]                         → WHERE column IS NULL
     *   ['column' => ['val1', 'val2']]             → WHERE column IN (val1, val2)
     *   ['column' => ['>', 100]]                   → WHERE column > 100
     *   ['column' => ['>=', 100]]                  → WHERE column >= 100
     *   ['column' => ['<=', 100]]                  → WHERE column <= 100
     *   ['column' => ['!=', 'x']]                  → WHERE column != 'x'
     *   ['column' => ['like', '%foo%']]             → WHERE column LIKE '%foo%'
     *   ['column' => ['between', 10, 99]]           → WHERE column BETWEEN 10 AND 99
     *   ['column' => ['not_null']]                  → WHERE column IS NOT NULL
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @return Builder<Model>|Relation<Model, Model, mixed>
     */
    public function applyWhere(Builder|Relation $query, ?Model $parent = null): Builder|Relation
    {
        // 1. Apply Scopes
        if ($this->scope !== null) {
            $scopes = is_array($this->scope) ? $this->scope : [$this->scope];
            foreach ($scopes as $scope) {
                // Determine if scope exists (we assume standard Laravel scope method signature)
                $query->{$scope}();
            }
        }

        // 2. Apply Where Conditions
        foreach ($this->where as $column => $value) {
            // Resolve dynamic parent attribute references (e.g., ':currency_code')
            if (is_string($value) && str_starts_with($value, ':') && $parent !== null) {
                $value = $parent->getAttribute(substr($value, 1));
            }

            if ($value === null) {
                // Explicit null → IS NULL
                $query->whereNull($column);
            } elseif (is_array($value) && isset($value[0]) && in_array($value[0], self::OPERATORS, true)) {
                // Operator syntax — first element is a recognized operator
                $operator = $value[0];
                $val1 = $value[1] ?? null;
                $val2 = $value[2] ?? null;

                // Resolve operator values dynamically as well
                if (is_string($val1) && str_starts_with($val1, ':') && $parent !== null) {
                    $val1 = $parent->getAttribute(substr($val1, 1));
                }
                if (is_string($val2) && str_starts_with($val2, ':') && $parent !== null) {
                    $val2 = $parent->getAttribute(substr($val2, 1));
                }

                if ($operator === 'between') {
                    $query->whereBetween($column, [$val1, $val2]);
                } elseif ($operator === 'not_null') {
                    $query->whereNotNull($column);
                } else {
                    $query->where($column, $operator, $val1);
                }
            } elseif (is_array($value)) {
                // Plain array → whereIn
                $query->whereIn($column, $value);
            } else {
                // Scalar → equality
                $query->where($column, $value);
            }
        }

        // 3. Apply Nested Relation Filters (whereHas)
        foreach ($this->whereHas as $relation => $conditions) {
            $query->whereHas($relation, function ($q) use ($conditions) {
                // Reuse a temporary definition to apply conditions to the subquery
                $subDef = new self(name: 'sub', method: 'exists', relation: null, column: '*', where: $conditions, ttl: null, cacheKey: null);
                $subDef->applyWhere($q);
            });
        }

        // 4. Apply Aging Buckets
        if ($this->aging !== null && isset($this->aging['column'], $this->aging['bucket'])) {
            $col = $this->aging['column'];
            $bucket = $this->aging['bucket'];

            // Current is due in the future or today
            if ($bucket === 'current') {
                $query->where($col, '>=', now()->format('Y-m-d'));
            } elseif (preg_match('/^(\d+)_(\d+)$/', $bucket, $matches)) {
                // e.g. 1_30
                $minDays = (int) $matches[1];
                $maxDays = (int) $matches[2];
                $query->whereBetween($col, [
                    now()->subDays($maxDays)->format('Y-m-d'),
                    now()->subDays($minDays)->format('Y-m-d'),
                ]);
            } elseif (preg_match('/^over_(\d+)$/', $bucket, $matches)) {
                // e.g. over_90
                $minDays = (int) $matches[1];
                $query->where($col, '<', now()->subDays($minDays)->format('Y-m-d'));
            }
        }

        return $query;
    }

    /**
     * Apply FlowFilter runtime values to the query.
     *
     * FlowFilters are runtime dimensions (e.g. date ranges, department codes)
     * that scope the aggregation without changing the FlowField definition.
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  array<string, mixed>  $filters  [column => value|[from, to]]
     * @return Builder<Model>|Relation<Model, Model, mixed>
     */
    public function applyFlowFilters(Builder|Relation $query, array $filters): Builder|Relation
    {
        foreach ($filters as $filterKey => $value) {
            if (is_array($value) && count($value) === 2) {
                // Date range / between filter: [from, to]
                $query->whereBetween($filterKey, $value);
            } elseif (is_array($value)) {
                // Multi-value filter → whereIn
                $query->whereIn($filterKey, $value);
            } elseif ($value === null) {
                $query->whereNull($filterKey);
            } else {
                // Scalar → equality
                $query->where($filterKey, $value);
            }
        }

        return $query;
    }

    /**
     * Return column names relevant to cache invalidation for this FlowField.
     *
     * @return array<string>
     */
    public function getRelevantColumns(): array
    {
        /** @var array<string> $columns */
        $columns = [];

        if ($this->column !== '*') {
            $columns[] = $this->column;
        }

        foreach ($this->where as $col => $value) {
            $columns[] = $col;
        }

        // For expression FlowFields, track explicitly declared columns
        foreach ($this->expressionColumns as $col) {
            $columns[] = $col;
        }

        return $columns;
    }

    /**
     * Check if this definition contains any dynamic parent attribute references (':column').
     * Such definitions cannot be evaluated in batch queries without a parent instance.
     */
    public function hasDynamicParameters(): bool
    {
        foreach ($this->where as $value) {
            if (is_string($value) && str_starts_with($value, ':')) {
                return true;
            }
            if (is_array($value) && isset($value[0]) && in_array($value[0], self::OPERATORS, true)) {
                if (isset($value[1]) && is_string($value[1]) && str_starts_with($value[1], ':')) {
                    return true;
                }
                if (isset($value[2]) && is_string($value[2]) && str_starts_with($value[2], ':')) {
                    return true;
                }
            }
        }

        return false;
    }
}
