<?php

namespace Schtzie\FlowField\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class FlowField
{
    public function __construct(
        public string $method,
        public string $relation,
        public string $column = '*',
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
         */
        public string|array|null $ofMany = null,
    ) {}
}
