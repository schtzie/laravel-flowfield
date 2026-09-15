<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Schtzie\FlowField\Concerns\HasFlowFields;

/**
 * FlowFieldResourceCollection — ResourceCollection with automatic batch loading.
 *
 * Extends Laravel's ResourceCollection to automatically batch-load FlowFields
 * for all models in the collection before serialization, eliminating N+1 queries.
 *
 * Usage:
 *
 *   class CustomerCollection extends FlowFieldResourceCollection
 *   {
 *       // Declare which FlowFields to pre-warm (empty = all)
 *       protected array $flowFields = ['balance', 'entry_count'];
 *   }
 *
 *   // In controller:
 *   return new CustomerCollection(Customer::paginate(50));
 *
 * The collection will automatically call batchCalcFlowFields() before
 * the first resource is serialized, resulting in one GROUP BY query per
 * FlowField rather than N queries.
 */
class FlowFieldResourceCollection extends ResourceCollection
{
    /**
     * FlowField names to pre-warm. Empty = warm all defined FlowFields.
     *
     * @var string[]
     */
    protected array $flowFields = [];

    /**
     * Whether auto-batch has already run for this collection instance.
     */
    protected bool $batchLoaded = false;

    /**
     * @return mixed
     */
    public function toArray($request): array|object
    {
        $this->ensureBatchLoaded();

        return parent::toArray($request);
    }

    /**
     * Trigger batch loading before JSON response is built.
     */
    public function toResponse($request): mixed
    {
        $this->ensureBatchLoaded();

        return parent::toResponse($request);
    }

    /**
     * Run batchCalcFlowFields() once for all models in the collection.
     * Skips if already run or if models do not use HasFlowFields.
     */
    protected function ensureBatchLoaded(): void
    {
        if ($this->batchLoaded) {
            return;
        }

        if (! config('flowfield.api.auto_batch', true)) {
            $this->batchLoaded = true;

            return;
        }

        $models = $this->collection
            ->map(fn ($resource) => $resource->resource ?? $resource)
            ->filter(fn ($model) => in_array(HasFlowFields::class, class_uses_recursive($model), true))
            ->values()
            ->all();

        if (empty($models)) {
            $this->batchLoaded = true;

            return;
        }

        $modelClass = get_class($models[0]);

        if (method_exists($modelClass, 'batchCalcFlowFields')) {
            $fields = $this->flowFields;

            if (empty($fields)) {
                $fields = array_keys($models[0]->getFlowFieldDefinitions());
            }

            $modelClass::batchCalcFlowFields($models, ...$fields);
        }

        $this->batchLoaded = true;
    }
}
