<?php

namespace Openplain\FlowField\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use InvalidArgumentException;

class FlowFieldCalculator
{
    public static function calculate(Model $parent, FlowFieldDefinition $definition): mixed
    {
        // Route through the ofMany path when the inline selector is declared.
        if ($definition->ofMany !== null) {
            return static::calculateOfMany($parent, $definition);
        }

        $query = $parent->{$definition->relation}();

        $definition->applyWhere($query);

        return match ($definition->method) {
            'sum'    => $query->sum($definition->column),
            'count'  => $definition->distinct
                            ? $query->distinct()->count($definition->column)
                            : $query->count($definition->column),
            'avg'    => $query->avg($definition->column),
            'min'    => $query->min($definition->column),
            'max'    => $query->max($definition->column),
            'exists' => $query->exists(),
            // Lookup: the 7th Navision FlowField type — fetches a single column
            // value from the first matching related record. Returns null when
            // no related record exists.
            'lookup' => $query->value($definition->column),
            default  => throw new InvalidArgumentException("Unsupported FlowField method: {$definition->method}"),
        };
    }

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
                . "Got: '{$definition->method}'."
            );
        }

        // Get the underlying hasMany relation to extract relation metadata.
        $hasManyRelation = $parent->{$definition->relation}();

        // Guard against polymorphic relations — the hasOne conversion would
        // omit the morph-type constraint and produce incorrect results.
        if ($hasManyRelation instanceof MorphOneOrMany) {
            throw new InvalidArgumentException(
                "FlowField 'ofMany' does not support polymorphic (morphMany) relations. "
                . "Define a dedicated hasOne()->ofMany() method on your model instead."
            );
        }

        $relatedClass = get_class($hasManyRelation->getRelated());
        $foreignKey   = $hasManyRelation->getForeignKeyName();
        $localKey     = $hasManyRelation->getLocalKeyName();

        // Build a hasOne with the same FK/local-key so we can chain ofMany variants.
        $hasOne = $parent->hasOne($relatedClass, $foreignKey, $localKey);

        $ofMany = $definition->ofMany;

        $query = match (true) {
            $ofMany === 'latest'   => $hasOne->latestOfMany(),
            $ofMany === 'oldest'   => $hasOne->oldestOfMany(),
            $ofMany === 'max'      => $hasOne->ofMany($definition->column, 'max'),
            $ofMany === 'min'      => $hasOne->ofMany($definition->column, 'min'),
            is_array($ofMany)      => $hasOne->ofMany($ofMany[0], $ofMany[1]),
            default                => throw new InvalidArgumentException(
                "Unknown ofMany value: '{$ofMany}'. "
                . "Expected: 'latest', 'oldest', 'max', 'min', or ['column', 'aggregate']."
            ),
        };

        // Apply any additional where filters (e.g. only invoices, not credits).
        $definition->applyWhere($query);

        return $query->value($definition->column);
    }
}
