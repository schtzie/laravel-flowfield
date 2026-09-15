<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Concerns;

use Illuminate\Http\Request;
use Schtzie\FlowField\Support\FlowFieldCache;

/**
 * FlowFieldResource — trait for Laravel JsonResource classes.
 *
 * Add to any JsonResource to gain FlowField-aware serialization:
 *
 *   class CustomerResource extends JsonResource
 *   {
 *       use FlowFieldResource;
 *
 *       public function toArray(Request $request): array
 *       {
 *           return [
 *               'id'      => $this->id,
 *               'name'    => $this->name,
 *               // Only include balance if it's loaded in cache
 *               'balance' => $this->whenFlowFieldLoaded('balance'),
 *               // Merge all FlowFields as a nested object
 *               ...      => $this->mergeFlowFields(),
 *           ];
 *       }
 *   }
 */
trait FlowFieldResource
{
    /**
     * Include a FlowField value only when it's already cached.
     *
     * Avoids triggering a DB query for un-warmed fields during serialization.
     * Falls back to $default when the field is not in cache.
     *
     * @param  string  $field  FlowField name (snake_case)
     * @param  mixed  $default  Value to return when not cached (default: null)
     */
    public function whenFlowFieldLoaded(string $field, mixed $default = null): mixed
    {
        $model = $this->resource;

        if (! method_exists($model, 'getFlowFieldDefinitions')) {
            return $default;
        }

        $definitions = $model->getFlowFieldDefinitions();

        if (! isset($definitions[$field])) {
            return $default;
        }

        // Check if the value is in the L2 cache (warm)
        $cached = FlowFieldCache::get($model, $definitions[$field]->getCacheKeyName());

        return $cached !== null ? $cached : $default;
    }

    /**
     * Return all (or specified) FlowField values as an array.
     *
     * If fields are pre-warmed (via withFlowFields or batchCalcFlowFields),
     * this is zero-query. Otherwise, it triggers calculation.
     *
     * @param  string[]  $fields  Specific fields to include (empty = all)
     * @return array<string, mixed>
     */
    public function flowFieldValues(string ...$fields): array
    {
        $model = $this->resource;

        if (! method_exists($model, 'getFlowFieldValues')) {
            return [];
        }

        return $model->getFlowFieldValues(...$fields);
    }

    /**
     * Return FlowField values as an array for merging into toArray().
     *
     * Respects sparse fieldsets if configured.
     *
     * @param  string[]  $fields  Specific fields to include (empty = all)
     * @return array<string, mixed>
     */
    public function toFlowFieldArray(string ...$fields): array
    {
        $model = $this->resource;

        if (! method_exists($model, 'getFlowFieldDefinitions')) {
            return [];
        }

        // Honour sparse fieldsets from query string
        $requestedFields = $this->requestedFlowFields(
            method_exists($this, 'request') ? $this->request : request()
        );

        if (! empty($requestedFields)) {
            $fields = empty($fields)
                ? $requestedFields
                : array_intersect($fields, $requestedFields);
        }

        return $model->getFlowFieldValues(...array_values($fields));
    }

    /**
     * Generate a merge array of FlowField values for spreading into toArray().
     *
     * Usage:
     *   return array_merge($this->mergeFlowFields('balance', 'entry_count'), [...]);
     *
     * @param  string[]  $fields  Specific fields (empty = all)
     * @return array<string, mixed>
     */
    public function mergeFlowFields(string ...$fields): array
    {
        return $this->toFlowFieldArray(...$fields);
    }

    /**
     * Parse requested FlowFields from the sparse fieldset query parameter.
     *
     * Supports the JSON:API sparse fieldsets format:
     *   GET /customers?fields[customers]=balance,entry_count
     *
     * @return string[]
     */
    public function requestedFlowFields(Request $request): array
    {
        if (! config('flowfield.api.sparse_fieldsets', true)) {
            return [];
        }

        $model = $this->resource;
        $type = method_exists($model, 'getTable') ? $model->getTable() : '';

        $fieldsParam = $request->query('fields', []);

        if (! is_array($fieldsParam) || ! isset($fieldsParam[$type])) {
            return [];
        }

        $requested = array_map('trim', explode(',', $fieldsParam[$type]));

        // Only return fields that are actually FlowFields
        $definitions = method_exists($model, 'getFlowFieldDefinitions')
            ? array_keys($model->getFlowFieldDefinitions())
            : [];

        return array_values(array_intersect($requested, $definitions));
    }
}
