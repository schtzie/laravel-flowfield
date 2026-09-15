<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class FlowField
{
    /**
     * @param  array<string, mixed>  $where  WHERE conditions applied to the relation query
     * @param  string|array{string, string}|null  $ofMany  ofMany selector for method: 'lookup'
     * @param  array<string>  $expressionColumns  Columns tracked for expression-FlowField invalidation
     * @param  array<string, array{string, string}>  $aggregates  Multi-aggregate definitions
     * @param  array<string>  $flowFilters  Runtime filter dimensions (for setFlowFilter)
     */
    public function __construct(
        public string $method,
        public ?string $relation = null,
        public string $column = '*',
        /** @var array<string, mixed> */
        public array $where = [],
        public ?int $ttl = null,
        public ?string $cacheKey = null,
        public bool $distinct = false,

        /**
         * Enables "one of many" selection without a dedicated relation method.
         * Only valid with method: 'lookup'.
         *
         * Accepted values:
         *   'latest'        → hasOne()->latestOfMany()
         *   'oldest'        → hasOne()->oldestOfMany()
         *   'max'           → hasOne()->ofMany($column, 'max')
         *   'min'           → hasOne()->ofMany($column, 'min')
         *   ['col', 'agg']  → hasOne()->ofMany($col, $agg)   (custom aggregate column)
         *
         * @var string|array{string, string}|null
         */
        public string|array|null $ofMany = null,

        /**
         * Raw SQL expression for method: 'expression'.
         *
         * Example:
         *   expression: 'COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)'
         *
         * The expression is injected as a SELECT raw into the relation query,
         * and the result is returned as a scalar value.
         */
        public ?string $expression = null,

        /**
         * Column list whose changes should trigger cache invalidation for
         * an 'expression' FlowField. Allows smart invalidation without
         * tracking every column in a complex expression.
         *
         * @var array<string>
         */
        public array $expressionColumns = [],

        /**
         * Grouped aggregate definitions for method: 'multi'.
         *
         * Runs a single query with multiple aggregates, storing each result
         * in cache separately. Eliminates N×M queries when a model has many
         * related aggregate FlowFields over the same relation.
         *
         * Format:
         *   ['field_name' => ['method', 'column']]
         *
         * Example:
         *   aggregates: [
         *       'debit_total'  => ['sum', 'debit_amount'],
         *       'credit_total' => ['sum', 'credit_amount'],
         *       'entry_count'  => ['count', '*'],
         *   ]
         *
         * @var array<string, array{string, string}>
         */
        public array $aggregates = [],

        /**
         * A closure (or class@method string) for method: 'subquery'.
         *
         * Receives ($query, $parent) and should return a scalar value.
         * Cannot be stored as a PHP attribute directly — use the
         * FlowField::subquery() fluent builder instead.
         */
        public mixed $query = null,

        /**
         * Runtime filter dimensions (FlowFilter support).
         *
         * Declares which columns can be filtered at runtime via
         * setFlowFilter() / withFlowFilter(). The filter values are
         * incorporated into the cache key for per-filter caching.
         *
         * Example:
         *   flowFilters: ['posting_date', 'department_code']
         *
         * @var array<string>
         */
        public array $flowFilters = [],

        /**
         * Eloquent scopes to apply to the relation query.
         * Example: 'posted' or ['posted', 'active']
         *
         * @var string|array<string>|null
         */
        public string|array|null $scope = null,

        /**
         * Nested relation filtering.
         * Example: ['header' => ['status' => 'Posted']]
         *
         * @var array<string, mixed>
         */
        public array $whereHas = [],

        /**
         * Aging bucket configuration for Accounts Receivable/Payable.
         * Example: ['column' => 'due_date', 'bucket' => '1_30']
         *
         * @var array{column: string, bucket: string}|null
         */
        public ?array $aging = null,

        /**
         * Weight column for Weighted Average ('wavg') method.
         *
         * @var string|null
         */
        public ?string $weightColumn = null,
    ) {}
}
