<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Console\Commands;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
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
        // argument() / option() return mixed — narrow to string|null
        $modelClass = is_string($this->argument('model')) ? $this->argument('model') : null;
        $id = is_scalar($this->option('id')) ? (string) $this->option('id') : null;
        $field = is_scalar($this->option('field')) ? (string) $this->option('field') : null;

        if ($modelClass !== null) {
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

        /** @var array<string>|null $fields */
        $fields = $field !== null ? [$field] : null;

        if ($id !== null) {
            /** @var class-string<Model> $modelClass */
            /** @var Model|null $model */
            $model = $modelClass::find($id);

            if ($model === null) {
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

        /** @var class-string<Model> $modelClass */
        $modelClass::query()->chunk(200, function (Collection $records) use ($bar, $fields): void {
            foreach ($records as $record) {
                /** @var Model $record */
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
