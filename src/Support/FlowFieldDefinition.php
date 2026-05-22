<?php

namespace Openplain\FlowField\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Openplain\FlowField\Attributes\FlowField;

class FlowFieldDefinition
{
    /**
     * Recognized comparison operators for where conditions.
     * Used to disambiguate operator arrays from whereIn arrays.
     */
    private const OPERATORS = ['>', '<', '>=', '<=', '!=', '<>', 'like', 'between', 'not_null'];

    public function __construct(
        public readonly string $name,
        public readonly string $method,
        public readonly string $relation,
        public readonly string $column,
        public readonly array $where,
        public readonly ?int $ttl,
        public readonly ?string $cacheKey,
        public readonly bool $distinct = false,
        public readonly string|array|null $ofMany = null,
    ) {}

    public static function fromAttribute(string $name, FlowField $attribute): static
    {
        return new static(
            name: $name,
            method: $attribute->method,
            relation: $attribute->relation,
            column: $attribute->column,
            where: $attribute->where,
            ttl: $attribute->ttl,
            cacheKey: $attribute->cacheKey,
            distinct: $attribute->distinct,
            ofMany: $attribute->ofMany,
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
     */
    public function applyWhere(Builder|Relation $query): Builder|Relation
    {
        foreach ($this->where as $column => $value) {
            if ($value === null) {
                // Explicit null → IS NULL
                $query->whereNull($column);
            } elseif (is_array($value) && isset($value[0]) && in_array($value[0], self::OPERATORS, true)) {
                // Operator syntax — first element is a recognized operator
                $operator = $value[0];

                if ($operator === 'between') {
                    $query->whereBetween($column, [$value[1], $value[2]]);
                } elseif ($operator === 'not_null') {
                    $query->whereNotNull($column);
                } else {
                    $query->where($column, $operator, $value[1]);
                }
            } elseif (is_array($value)) {
                // Plain array → whereIn
                $query->whereIn($column, $value);
            } else {
                // Scalar → equality
                $query->where($column, $value);
            }
        }

        return $query;
    }

    public function getRelevantColumns(): array
    {
        $columns = [];

        if ($this->column !== '*') {
            $columns[] = $this->column;
        }

        foreach ($this->where as $col => $value) {
            $columns[] = $col;
        }

        return $columns;
    }
}
