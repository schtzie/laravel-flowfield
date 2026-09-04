<?php

namespace Schtzie\FlowField\Console\Commands;

use Schtzie\FlowField\Support\FlowFieldCache;

class FlowFieldWarmCommand extends BaseFlowFieldCommand
{
    protected $signature = 'flowfield:warm
                            {model? : The model class to warm (e.g. App\\Models\\Customer)}
                            {--id= : Warm a specific record by ID}
                            {--field= : Warm a specific field only}';

    protected $description = 'Warm FlowField cache for all or specific models';

    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $id = $this->option('id');
        $field = $this->option('field');

        if ($modelClass) {
            return $this->warmModel($modelClass, $id, $field);
        }

        $models = $this->discoverModels();

        if (empty($models)) {
            $this->info('No models with HasFlowFields trait found.');

            return self::SUCCESS;
        }

        foreach ($models as $model) {
            $this->warmModel($model, $id, $field);
        }

        return self::SUCCESS;
    }

    protected function warmModel(string $modelClass, ?string $id, ?string $field): int
    {
        if (! $this->validateModelClass($modelClass)) {
            return self::FAILURE;
        }

        $fields = $field ? [$field] : null;

        if ($id) {
            $model = $modelClass::find($id);

            if (! $model) {
                $this->error("Record {$id} not found in {$modelClass}.");

                return self::FAILURE;
            }

            FlowFieldCache::warm($model, $fields);
            $this->info("Warmed FlowFields for {$modelClass} #{$id}.");

            return self::SUCCESS;
        }

        $this->info("Warming FlowFields for {$modelClass}...");

        $bar = $this->output->createProgressBar();
        $bar->start();

        $modelClass::query()->chunk(200, function ($records) use ($bar, $fields) {
            foreach ($records as $record) {
                FlowFieldCache::warm($record, $fields);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
