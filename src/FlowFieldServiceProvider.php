<?php

namespace Schtzie\FlowField;

use Illuminate\Support\ServiceProvider;
use Schtzie\FlowField\Console\Commands\FlowFieldFlushCommand;
use Schtzie\FlowField\Console\Commands\FlowFieldWarmCommand;

class FlowFieldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/flowfield.php', 'flowfield');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/flowfield.php' => config_path('flowfield.php'),
            ], 'flowfield-config');

            $this->commands([
                FlowFieldWarmCommand::class,
                FlowFieldFlushCommand::class,
            ]);
        }
    }
}
