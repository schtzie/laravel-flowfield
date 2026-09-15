<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Support;

/**
 * FlowFieldOpenApi — generates OpenAPI schema properties from FlowField definitions.
 *
 * Usage:
 *   $properties = FlowFieldOpenApi::schemaProperties(Customer::class);
 *   // Returns an array suitable for OpenAPI schema 'properties' block.
 *
 * Example output:
 *   [
 *     'balance'     => ['type' => 'number',  'readOnly' => true, 'description' => 'sum of entries.amount'],
 *     'entry_count' => ['type' => 'integer', 'readOnly' => true, 'description' => 'count of entries.*'],
 *     'has_entries' => ['type' => 'boolean', 'readOnly' => true, 'description' => 'exists on entries'],
 *   ]
 */
class FlowFieldOpenApi
{
    /**
     * Generate OpenAPI schema properties for all FlowFields on a model class.
     *
     * @param  class-string  $modelClass  FQCN of an Eloquent model using HasFlowFields
     * @param  array<string>  $fields  Specific fields to include (empty = all)
     * @return array<string, array<string, mixed>>
     */
    public static function schemaProperties(string $modelClass, array $fields = []): array
    {
        if (! method_exists($modelClass, 'getFlowFieldDefinitions')) {
            return [];
        }

        /** @var array<string, FlowFieldDefinition> $definitions */
        $definitions = (new $modelClass)->getFlowFieldDefinitions();

        if (! empty($fields)) {
            $definitions = array_filter(
                $definitions,
                static fn (FlowFieldDefinition $def): bool => in_array($def->name, $fields, true)
            );
        }

        /** @var array<string, string> $typeMap */
        $typeMap = config('flowfield.api.openapi_types') ?? static::defaultTypeMap();
        /** @var array<string, array<string, mixed>> $properties */
        $properties = [];

        foreach ($definitions as $name => $definition) {
            $type = $typeMap[$definition->method] ?? 'mixed';
            $description = static::buildDescription($definition);

            /** @var array<string, mixed> $property */
            $property = [
                'type' => $type,
                'readOnly' => true,
                'description' => $description,
            ];

            // Add nullable for lookup/expression that might return null
            if (in_array($definition->method, ['lookup', 'expression', 'subquery'], true)) {
                $property['nullable'] = true;
            }

            // Add format hints for numeric types
            if ($type === 'number') {
                $property['format'] = 'float';
            }

            $properties[(string) $name] = $property;
        }

        return $properties;
    }

    /**
     * Generate a complete OpenAPI schema object for a model's FlowFields.
     *
     * @param  class-string  $modelClass
     * @param  string|null  $schemaName  Name for the schema (defaults to model basename)
     * @return array<string, array<string, mixed>>
     */
    public static function schema(string $modelClass, ?string $schemaName = null): array
    {
        $name = $schemaName ?? class_basename($modelClass).'FlowFields';

        return [
            $name => [
                'type' => 'object',
                'readOnly' => true,
                'properties' => static::schemaProperties($modelClass),
            ],
        ];
    }

    /**
     * Default method-to-OpenAPI-type mapping.
     *
     * @return array<string, string>
     */
    public static function defaultTypeMap(): array
    {
        return [
            'sum' => 'number',
            'count' => 'integer',
            'avg' => 'number',
            'min' => 'number',
            'max' => 'number',
            'exists' => 'boolean',
            'lookup' => 'string',
            'expression' => 'number',
            'multi' => 'object',
            'subquery' => 'number',
        ];
    }

    /**
     * Build a human-readable description for a FlowField.
     */
    protected static function buildDescription(FlowFieldDefinition $definition): string
    {
        return match ($definition->method) {
            'sum' => "sum of {$definition->relation}.{$definition->column}",
            'count' => "count of {$definition->relation}.{$definition->column}",
            'avg' => "average of {$definition->relation}.{$definition->column}",
            'min' => "minimum {$definition->relation}.{$definition->column}",
            'max' => "maximum {$definition->relation}.{$definition->column}",
            'exists' => "exists on {$definition->relation}",
            'lookup' => "lookup {$definition->column} from {$definition->relation}",
            'expression' => 'expression: '.($definition->expression ?? ''),
            'multi' => "multi-aggregate on {$definition->relation}",
            'subquery' => "subquery on {$definition->relation}",
            default => $definition->method.' on '.$definition->relation,
        };
    }
}
