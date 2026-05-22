<?php

namespace Openplain\FlowField\Concerns;

use Openplain\FlowField\Support\FlowFieldCache;

trait InvalidatesFlowFields
{
    /**
     * Polymorphic morph-relation base names whose parent models should have
     * their FlowFields invalidated when this model changes.
     *
     * Define this property on your model class (not here in the trait):
     *
     *   protected array $morphFlowFieldTargets = [
     *       'commentable',                              // standard naming convention
     *       ['type' => 'ref_type', 'id' => 'ref_id'],  // custom column names
     *   ];
     *
     * Each entry is the base name of the morph relation (e.g. 'commentable'),
     * and the trait automatically resolves the '{name}_type' and '{name}_id'
     * columns to find the parent model class and ID at runtime.
     */

    public static function bootInvalidatesFlowFields(): void
    {
        static::created(function ($model) {
            $model->invalidateFlowFieldTargets();
        });

        static::updated(function ($model) {
            $model->invalidateFlowFieldTargetsOnUpdate();
        });

        static::deleted(function ($model) {
            $model->invalidateFlowFieldTargets();
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                $model->invalidateFlowFieldTargets();
            });
        }
    }

    protected function invalidateFlowFieldTargets(): void
    {
        // Regular (non-polymorphic) targets
        foreach ($this->flowFieldTargets as $targetClass => $foreignKey) {
            $parentId = $this->getAttribute($foreignKey);

            if ($parentId !== null) {
                $this->invalidateAndMaybeWarm($targetClass, $parentId);
            }
        }

        // Polymorphic targets
        foreach ($this->resolveMorphTargets() as [$parentClass, $parentId]) {
            $this->invalidateAndMaybeWarm($parentClass, $parentId);
        }
    }

    protected function invalidateFlowFieldTargetsOnUpdate(): void
    {
        // Regular (non-polymorphic) targets
        foreach ($this->flowFieldTargets as $targetClass => $foreignKey) {
            $parentId = $this->getAttribute($foreignKey);
            $foreignKeyChanged = $this->wasChanged($foreignKey);

            if (! $foreignKeyChanged && ! $this->hasRelevantChanges($targetClass)) {
                continue;
            }

            if ($parentId !== null) {
                $this->invalidateAndMaybeWarm($targetClass, $parentId);
            }

            if ($foreignKeyChanged) {
                $oldParentId = $this->getOriginal($foreignKey);

                if ($oldParentId !== null && $oldParentId !== $parentId) {
                    $this->invalidateAndMaybeWarm($targetClass, $oldParentId);
                }
            }
        }

        // Polymorphic targets
        foreach ($this->morphFlowFieldTargets ?? [] as $target) {
            [$typeColumn, $idColumn] = $this->resolveMorphColumns($target);

            $parentClass = $this->getAttribute($typeColumn);
            $parentId    = $this->getAttribute($idColumn);
            $morphChanged = $this->wasChanged($typeColumn) || $this->wasChanged($idColumn);

            // Skip if morph pointer unchanged AND no relevant column changes for this parent
            if (! $morphChanged && $parentClass && ! $this->hasRelevantChanges($parentClass)) {
                continue;
            }

            if ($parentClass && $parentId !== null) {
                $this->invalidateAndMaybeWarm($parentClass, $parentId);
            }

            // If the morph target was reassigned, also invalidate the OLD parent
            if ($morphChanged) {
                $oldParentClass = $this->getOriginal($typeColumn);
                $oldParentId    = $this->getOriginal($idColumn);

                if ($oldParentClass && $oldParentId !== null
                    && ($oldParentClass !== $parentClass || $oldParentId !== $parentId)
                ) {
                    $this->invalidateAndMaybeWarm($oldParentClass, $oldParentId);
                }
            }
        }
    }

    protected function invalidateAndMaybeWarm(string $targetClass, int|string $parentId): void
    {
        FlowFieldCache::invalidateAll($targetClass, $parentId);

        if (config('flowfield.auto_warm', false)) {
            $parent = $targetClass::find($parentId);

            if ($parent) {
                FlowFieldCache::warm($parent);
            }
        }
    }

    protected function hasRelevantChanges(string $targetClass): bool
    {
        if (! in_array(HasFlowFields::class, class_uses_recursive($targetClass))) {
            return true;
        }

        $definitions = $targetClass::getFlowFieldDefinitions();
        $changedColumns = array_keys($this->getChanges());

        foreach ($definitions as $definition) {
            // For count/exists with no where conditions, an update can't change the result
            // (only inserts/deletes matter)
            if (in_array($definition->method, ['count', 'exists']) && empty($definition->where)) {
                continue;
            }

            $relevantColumns = $definition->getRelevantColumns();

            if (empty($relevantColumns)) {
                return true;
            }

            foreach ($relevantColumns as $column) {
                if (in_array($column, $changedColumns)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve all current (parentClass, parentId) pairs from $morphFlowFieldTargets.
     *
     * @return array<int, array{0: string, 1: int|string}>
     */
    private function resolveMorphTargets(): array
    {
        $pairs = [];

        foreach ($this->morphFlowFieldTargets ?? [] as $target) {
            [$typeColumn, $idColumn] = $this->resolveMorphColumns($target);

            $parentClass = $this->getAttribute($typeColumn);
            $parentId    = $this->getAttribute($idColumn);

            if ($parentClass !== null && $parentId !== null) {
                $pairs[] = [$parentClass, $parentId];
            }
        }

        return $pairs;
    }

    /**
     * Resolve morph column names from a target declaration.
     *
     * Supports:
     *   'commentable'                            → ['commentable_type', 'commentable_id']
     *   ['type' => 'ref_type', 'id' => 'ref_id'] → ['ref_type', 'ref_id']
     *
     * @return array{0: string, 1: string}
     */
    private function resolveMorphColumns(string|array $target): array
    {
        if (is_string($target)) {
            return ["{$target}_type", "{$target}_id"];
        }

        return [$target['type'], $target['id']];
    }
}
