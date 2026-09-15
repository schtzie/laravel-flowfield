<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Console\Commands;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Schtzie\FlowField\Support\FlowFieldCache;

class FlowFieldFlushCommand extends BaseFlowFieldCommand
{
    protected $signature = 'flowfield:flush
                            {model? : The model class to flush (e.g. App\\Models\\Customer)}
                            {--id= : Flush a specific record by ID}';

    protected $description = 'Flush cached FlowField values';

    public function handle(): int
    {
        // argument() returns mixed — narrow to string|null
        $modelClass = is_string($this->argument('model')) ? $this->argument('model') : null;
        // option() returns mixed — narrow to string|null
        $id = is_scalar($this->option('id')) ? (string) $this->option('id') : null;

        if ($modelClass !== null) {
            return $this->flushModel($modelClass, $id);
        }

        // At this point $modelClass is empty — only --id without a model is invalid.
        if ($id !== null) {
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

        if ($id !== null) {
            /** @var class-string<Model> $modelClass */
            FlowFieldCache::invalidateAll($modelClass, $id);
            $this->info("Flushed FlowFields for {$modelClass} #{$id}.");

            return self::SUCCESS;
        }

        $this->info("Flushing FlowFields for {$modelClass}...");

        /** @var class-string<Model> $modelClass */
        $modelClass::query()->chunk(200, function (Collection $records) use ($modelClass): void {
            foreach ($records as $record) {
                /** @var Model $record */
                FlowFieldCache::invalidateAll($modelClass, (string) $record->getKey());
            }
        });

        $this->info("Flushed all FlowField caches for {$modelClass}.");

        return self::SUCCESS;
    }
}
