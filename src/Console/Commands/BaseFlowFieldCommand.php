<?php

namespace Schtzie\FlowField\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Schtzie\FlowField\Concerns\HasFlowFields;

abstract class BaseFlowFieldCommand extends Command
{
    protected function validateModelClass(string $modelClass): bool
    {
        if (! class_exists($modelClass)) {
            $this->error("Model class {$modelClass} not found.");

            return false;
        }

        if (! in_array(HasFlowFields::class, class_uses_recursive($modelClass))) {
            $this->error("Model {$modelClass} does not use the HasFlowFields trait.");

            return false;
        }

        return true;
    }

    protected function discoverModels(): array
    {
        $modelsPath = app_path('Models');

        if (! is_dir($modelsPath)) {
            return [];
        }

        $models = [];

        foreach (File::allFiles($modelsPath) as $file) {
            $className = 'App\\Models\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                $file->getRelativePathname()
            );

            if (! class_exists($className)) {
                continue;
            }

            if (in_array(HasFlowFields::class, class_uses_recursive($className))) {
                $models[] = $className;
            }
        }

        return $models;
    }
}
