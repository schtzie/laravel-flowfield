<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FlowFieldCalculator
{
    public static function calculate(Model $parent, FlowFieldDefinition $definition): mixed
    {
        // Route through the ofMany path when the inline selector is declared.
        if ($definition->ofMany !== null) {
            return static::calculateOfMany($parent, $definition);
        }

        // Route raw expression calculation.
        if ($definition->method === 'expression') {
            return static::calculateExpression($parent, $definition);
        }

        // Route formula calculation.
        if ($definition->method === 'formula') {
            return static::calculateFormula($parent, $definition);
        }

        // Route multi-aggregate calculation (handled at the cache layer).
        if ($definition->method === 'multi') {
            return static::calculateMulti($parent, $definition)[$definition->name] ?? null;
        }

        // Route subquery calculation via callable closure.
        if ($definition->method === 'subquery') {
            return static::calculateSubquery($parent, $definition);
        }

        /** @var Builder<Model>|Relation<Model, Model, mixed> $query */
        $query = $parent->{$definition->relation}();

        $definition->applyWhere($query, $parent);

        return match ($definition->method) {
            'sum' => $query->sum($definition->column),
            'count' => $definition->distinct
                            ? $query->distinct()->count($definition->column)
                            : $query->count($definition->column),
            'avg' => $query->avg($definition->column),
            'wavg' => static::calculateWavg($query, $definition),
            'min' => $query->min($definition->column),
            'max' => $query->max($definition->column),
            'exists' => $query->exists(),
            // Lookup: the 7th Navision FlowField type — fetches a single column
            // value from the first matching related record. Returns null when
            // no related record exists.
            'lookup' => $query->value($definition->column),
            default => throw new InvalidArgumentException("Unsupported FlowField method: {$definition->method}"),
        };
    }

    // -------------------------------------------------------------------------
    // Phase 1: multi method
    // -------------------------------------------------------------------------

    /**
     * Calculate all named aggregates in a single query.
     *
     * Returns an associative array of [field_name => value] for all aggregates
     * defined in $definition->aggregates. Results are stored to cache by the
     * HasFlowFields trait after calling this method.
     *
     * Example aggregates:
     *   [
     *     'debit_total'  => ['sum', 'debit_amount'],
     *     'credit_total' => ['sum', 'credit_amount'],
     *     'entry_count'  => ['count', '*'],
     *   ]
     *
     * @return array<string, mixed>
     */
    public static function calculateMulti(Model $parent, FlowFieldDefinition $definition): array
    {
        if (empty($definition->aggregates)) {
            return [];
        }

        /** @var Builder<Model>|Relation<Model, Model, mixed> $query */
        $query = $parent->{$definition->relation}();
        $definition->applyWhere($query, $parent);

        // Build SELECT expressions for every declared aggregate
        $selects = [];
        foreach ($definition->aggregates as $fieldName => $spec) {
            /** @var array{string, string} $spec */
            [$aggMethod, $aggColumn] = $spec;
            $safeField = str_replace(['.', ' ', '-'], '_', $fieldName);

            $selects[] = match ($aggMethod) {
                'sum' => "COALESCE(SUM({$aggColumn}), 0) as ff_{$safeField}",
                'count' => $aggColumn === '*'
                                ? "COUNT(*) as ff_{$safeField}"
                                : "COUNT({$aggColumn}) as ff_{$safeField}",
                'avg' => "AVG({$aggColumn}) as ff_{$safeField}",
                'min' => "MIN({$aggColumn}) as ff_{$safeField}",
                'max' => "MAX({$aggColumn}) as ff_{$safeField}",
                'exists' => "CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END as ff_{$safeField}",
                default => throw new InvalidArgumentException("Unsupported aggregate method: {$aggMethod}"),
            };
        }

        /** @phpstan-ignore argument.type (Dynamic selectRaw is intended here) */
        $row = $query->selectRaw(implode(', ', $selects))->first();

        if ($row === null) {
            // No related rows — return zeros
            /** @var array<string, mixed> $zeros */
            $zeros = array_map(static fn (): int => 0, $definition->aggregates);

            return $zeros;
        }

        /** @var array<string, mixed> $results */
        $results = [];
        foreach ($definition->aggregates as $fieldName => $spec) {
            /** @var array{string, string} $spec */
            $safeField = str_replace(['.', ' ', '-'], '_', $fieldName);
            [$aggMethod] = $spec;
            $raw = $row->{"ff_{$safeField}"};
            // Cast exists to boolean
            $results[$fieldName] = $aggMethod === 'exists' ? (bool) $raw : $raw;
        }

        return $results;
    }

    /**
     * Calculate Weighted Average Cost (WAVG).
     * SQL: SUM(column * weightColumn) / SUM(weightColumn)
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     */
    protected static function calculateWavg(Builder|Relation $query, FlowFieldDefinition $definition): float
    {
        if (! $definition->weightColumn) {
            throw new InvalidArgumentException("FlowField '{$definition->name}' uses method 'wavg' but 'weightColumn' is missing.");
        }

        $valCol = $definition->column;
        $wtCol = $definition->weightColumn;

        // Uses selectRaw to calculate WAVG securely at DB level, avoiding N+1
        /** @phpstan-ignore-next-line */
        $result = $query->selectRaw("COALESCE(SUM({$valCol} * {$wtCol}) / NULLIF(SUM({$wtCol}), 0), 0) as wavg")->value('wavg');

        return (float) $result;
    }

    // -------------------------------------------------------------------------
    // Phase 1: expression method
    // -------------------------------------------------------------------------

    /**
     * Calculate a raw SQL expression FlowField.
     *
     * The expression is injected as a selectRaw() on the relation query and
     * a single scalar value is returned via value('flow_value').
     *
     * Example:
     *   expression: 'COALESCE(SUM(debit_amount), 0) - COALESCE(SUM(credit_amount), 0)'
     */
    protected static function calculateExpression(Model $parent, FlowFieldDefinition $definition): mixed
    {
        if (! $definition->expression) {
            throw new InvalidArgumentException(
                "FlowField '{$definition->name}' uses method 'expression' but has no 'expression' SQL defined."
            );
        }

        /** @var Builder<Model>|Relation<Model, Model, mixed> $query */
        $query = $parent->{$definition->relation}();
        $definition->applyWhere($query, $parent);

        /** @phpstan-ignore-next-line */
        return $query->selectRaw("({$definition->expression}) as flow_value")->value('flow_value');
    }

    // -------------------------------------------------------------------------
    // Phase 1: formula method
    // -------------------------------------------------------------------------

    /**
     * Calculate a mathematical formula using parent model attributes.
     * Evaluates in PHP rather than SQL, allowing composition of other FlowFields.
     */
    protected static function calculateFormula(Model $parent, FlowFieldDefinition $definition): mixed
    {
        if (! $definition->expression) {
            throw new InvalidArgumentException(
                "FlowField '{$definition->name}' uses method 'formula' but has no 'expression' defined."
            );
        }

        return FormulaEvaluator::evaluate($definition->expression, $parent);
    }

    // -------------------------------------------------------------------------
    // Phase 1: subquery method
    // -------------------------------------------------------------------------

    /**
     * Execute a developer-provided closure as the calculation.
     *
     * The closure receives ($relationQuery, $parentModel) and must return
     * a scalar value. This allows arbitrary Eloquent/DB queries beyond what
     * the standard aggregation methods support.
     *
     * Note: Subquery FlowFields bypass the standard cache key derivation —
     * use cacheKey parameter to set an explicit key for invalidation targets.
     */
    protected static function calculateSubquery(Model $parent, FlowFieldDefinition $definition): mixed
    {
        if (! is_callable($definition->query)) {
            throw new InvalidArgumentException(
                "FlowField '{$definition->name}' uses method 'subquery' but 'query' is not callable."
            );
        }

        /** @var Builder<Model>|Relation<Model, Model, mixed> $query */
        $query = $parent->{$definition->relation}();
        $definition->applyWhere($query, $parent);

        return ($definition->query)($query, $parent);
    }

    // -------------------------------------------------------------------------
    // ofMany (unchanged)
    // -------------------------------------------------------------------------

    /**
     * Handle the ofMany shorthand: converts a hasMany relation into a
     * hasOneOfMany relation and reads a single column value from the
     * selected record.
     *
     * Only valid with method: 'lookup'. Polymorphic (morphMany) relations
     * are not supported — define a dedicated hasOne()->ofMany() relation
     * method on the model instead.
     */
    protected static function calculateOfMany(Model $parent, FlowFieldDefinition $definition): mixed
    {
        if ($definition->method !== 'lookup') {
            throw new InvalidArgumentException(
                "FlowField 'ofMany' is only valid with method: 'lookup'. "
                ."Got: '{$definition->method}'."
            );
        }

        // Get the underlying hasMany relation to extract relation metadata.
        $hasManyRelation = $parent->{$definition->relation}();

        // Guard against polymorphic relations — the hasOne conversion would
        // omit the morph-type constraint and produce incorrect results.
        if ($hasManyRelation instanceof MorphOneOrMany) {
            throw new InvalidArgumentException(
                "FlowField 'ofMany' does not support polymorphic (morphMany) relations. "
                .'Define a dedicated hasOne()->ofMany() method on your model instead.'
            );
        }

        // PHPStan: narrow to HasMany so we can call getRelated/getForeignKeyName/getLocalKeyName
        if (! $hasManyRelation instanceof HasMany) {
            throw new InvalidArgumentException(
                "FlowField 'ofMany' requires a hasMany() relation. Got: ".get_class($hasManyRelation)
            );
        }

        $relatedClass = get_class($hasManyRelation->getRelated());
        $foreignKey = $hasManyRelation->getForeignKeyName();
        $localKey = $hasManyRelation->getLocalKeyName();

        // Build a hasOne with the same FK/local-key so we can chain ofMany variants.
        $hasOne = $parent->hasOne($relatedClass, $foreignKey, $localKey);

        $ofMany = $definition->ofMany;

        $query = match (true) {
            $ofMany === 'latest' => $hasOne->latestOfMany(),
            $ofMany === 'oldest' => $hasOne->oldestOfMany(),
            $ofMany === 'max' => $hasOne->ofMany($definition->column, 'max'),
            $ofMany === 'min' => $hasOne->ofMany($definition->column, 'min'),
            is_array($ofMany) => $hasOne->ofMany($ofMany[0], $ofMany[1]),
            default => throw new InvalidArgumentException(
                "Unknown ofMany value: '{$ofMany}'. "
                ."Expected: 'latest', 'oldest', 'max', 'min', or ['column', 'aggregate']."
            ),
        };

        // Apply any additional where filters (e.g. only invoices, not credits).
        $definition->applyWhere($query, $parent);

        return $query->value($definition->column);
    }
}
