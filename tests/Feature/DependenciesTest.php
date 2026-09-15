<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Laravel\Octane\Events\RequestReceived;
use Schtzie\FlowField\FlowFieldServiceProvider;
use Schtzie\FlowField\Listeners\OctaneFlowFieldListener;
use Schtzie\FlowField\Support\FlowFieldQueryTracker;

it('octane request received event resets flow field cache static state', function () {
    // Setup some static state
    config(['flowfield.query_deduplication.enabled' => true]);
    FlowFieldQueryTracker::set('test_key', 123);
    expect(FlowFieldQueryTracker::has('test_key'))->toBeTrue();

    // Trigger the Octane RequestReceived event
    $listener = new OctaneFlowFieldListener;
    $event = new RequestReceived(app(), app(), new Request);
    $listener->handleRequestReceived($event);

    // Assert state is reset
    expect(FlowFieldQueryTracker::has('test_key'))->toBeFalse();
});

it('disables tag_based config automatically for dynamodb driver', function () {
    // Force the cache driver to dynamodb
    config(['cache.stores.dynamodb' => ['driver' => 'dynamodb']]);
    config(['cache.default' => 'dynamodb']);
    config(['flowfield.cache.store' => 'dynamodb']);
    config(['flowfield.tag_based' => true]);

    // Re-boot the service provider
    $provider = new FlowFieldServiceProvider(app());
    // Use reflection to call the protected method bootAutoDriverDetection
    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('bootAutoDriverDetection');
    $method->setAccessible(true);
    $method->invoke($provider);

    expect(config('flowfield.tag_based'))->toBeFalse();
});

it('maintains tag_based config for redis driver', function () {
    // Force the cache driver to redis
    config(['cache.stores.redis' => ['driver' => 'redis']]);
    config(['cache.default' => 'redis']);
    config(['flowfield.cache.store' => 'redis']);
    config(['flowfield.tag_based' => true]);

    // Re-boot the service provider
    $provider = new FlowFieldServiceProvider(app());
    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('bootAutoDriverDetection');
    $method->setAccessible(true);
    $method->invoke($provider);

    expect(config('flowfield.tag_based'))->toBeTrue();
});
