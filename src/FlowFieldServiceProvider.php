<?php

declare(strict_types=1);

namespace Schtzie\FlowField;

use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Octane;
use Schtzie\FlowField\Console\Commands\FlowFieldFlushCommand;
use Schtzie\FlowField\Console\Commands\FlowFieldWarmCommand;
use Schtzie\FlowField\Listeners\OctaneFlowFieldListener;

class FlowFieldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/flowfield.php', 'flowfield');
    }

    public function boot(): void
    {
        $this->bootAutoDriverDetection();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/flowfield.php' => config_path('flowfield.php'),
            ], 'flowfield-config');

            $this->commands([
                FlowFieldWarmCommand::class,
                FlowFieldFlushCommand::class,
            ]);
        }

        $this->bootOctaneListeners();
    }

    /**
     * Auto-detect cache drivers that do not support tags and disable tag_based
     * automatically. This prevents runtime errors when a developer configures
     * a non-taggable store (file, database, dynamodb) without setting tag_based.
     */
    protected function bootAutoDriverDetection(): void
    {
        // Only apply auto-detection if tag_based is still true (not explicitly disabled)
        if (! config('flowfield.tag_based', true)) {
            return;
        }

        /** @var string|null $store */
        $store = config('flowfield.cache.store') ?? config('cache.default');

        if (! is_string($store)) {
            return;
        }

        $driver = config("cache.stores.{$store}.driver");

        $nonTaggableDrivers = ['file', 'database', 'dynamodb', 'null'];

        if (in_array($driver, $nonTaggableDrivers, true)) {
            config(['flowfield.tag_based' => false]);
        }
    }

    /**
     * Register Laravel Octane event listeners when Octane is present.
     *
     * Handles all three Octane server backends:
     *   - FrankenPHP  → RequestReceived / RequestTerminated
     *   - RoadRunner  → RequestReceived / RequestTerminated / TickReceived
     *   - Swoole      → RequestReceived / RequestTerminated / TaskReceived
     */
    protected function bootOctaneListeners(): void
    {
        if (! class_exists(Octane::class)) {
            return;
        }

        $listener = new OctaneFlowFieldListener;

        $events = $this->app->make('events');

        // Runs at the start of every request in all Octane servers
        if (class_exists(RequestReceived::class)) {
            $events->listen(
                RequestReceived::class,
                [$listener, 'handleRequestReceived']
            );
        }

        // Runs at the end of every request in all Octane servers
        if (class_exists(RequestTerminated::class)) {
            $events->listen(
                RequestTerminated::class,
                [$listener, 'handleRequestTerminated']
            );
        }

        // Swoole task workers
        if (class_exists(TaskReceived::class)) {
            $events->listen(
                TaskReceived::class,
                [$listener, 'handleTaskReceived']
            );
        }

        // RoadRunner ticker
        if (class_exists(TickReceived::class)) {
            $events->listen(
                TickReceived::class,
                [$listener, 'handleTickReceived']
            );
        }

        // Octane worker boot
        if (class_exists(\Laravel\Octane\Events\WorkerStarting::class)) {
            $events->listen(
                \Laravel\Octane\Events\WorkerStarting::class,
                [$listener, 'handleWorkerStarting']
            );
        }
    }
}
