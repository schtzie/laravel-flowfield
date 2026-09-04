<?php

namespace Schtzie\FlowField\Console\Commands;

use Schtzie\FlowField\Support\FlowFieldCache;

class FlowFieldFlushCommand extends BaseFlowFieldCommand
{
    protected $signature = 'flowfield:flush
                            {model? : The model class to flush (e.g. App\\Models\\Customer)}
                            {--id= : Flush a specific record by ID}';

    protected $description = 'Flush cached FlowField values';

    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $id = $this->option('id');

        if ($modelClass) {
            return $this->flushModel($modelClass, $id);
        }

        if (! $modelClass && $id) {
            $this->error('You must specify a model when using --id.');

            return self::FAILURE;
        }

        $models = $this->discoverModels();

        if (empty($models)) {
            $this->info('No models with HasFlowFields trait found.');

            return self::SUCCESS;
        }

        foreach ($models as $model) {
            $this->flushModel($model, null);
        }

        $this->info('All FlowField caches flushed.');

        return self::SUCCESS;
    }

    protected function flushModel(string $modelClass, ?string $id): int
    {
        if (! $this->validateModelClass($modelClass)) {
            return self::FAILURE;
        }

        if ($id) {
            FlowFieldCache::invalidateAll($modelClass, $id);
            $this->info("Flushed FlowFields for {$modelClass} #{$id}.");

            return self::SUCCESS;
        }

        $this->info("Flushing FlowFields for {$modelClass}...");

        $modelClass::query()->chunk(200, function ($records) use ($modelClass) {
            foreach ($records as $record) {
                FlowFieldCache::invalidateAll($modelClass, $record->getKey());
            }
        });

        $this->info("Flushed all FlowField caches for {$modelClass}.");

        return self::SUCCESS;
    }
}
