<?php

declare(strict_types=1);

use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;

it('builds cache key in prefix:table:id:field format', function () {
    $customer = TestCustomer::create(['name' => 'Test']);
    $key = FlowFieldCache::buildKey($customer, 'balance');

    expect($key)->toBe("flowfield:test_customers:{$customer->id}:balance");
});

it('puts and retrieves a value', function () {
    $customer = TestCustomer::create(['name' => 'Test']);
    FlowFieldCache::put($customer, 'balance', 100.50);

    expect(FlowFieldCache::get($customer, 'balance'))->toBe(100.50);
});

it('get returns cached value', function () {
    $customer = TestCustomer::create(['name' => 'Test']);
    FlowFieldCache::put($customer, 'balance', 42);

    expect(FlowFieldCache::get($customer, 'balance'))->toBe(42);
});

it('get returns null for missing values', function () {
    $customer = TestCustomer::create(['name' => 'Test']);

    expect(FlowFieldCache::get($customer, 'balance'))->toBeNull();
});

it('invalidate removes only the specified field', function () {
    $customer = TestCustomer::create(['name' => 'Test']);

    FlowFieldCache::put($customer, 'balance', 100);
    FlowFieldCache::put($customer, 'entry_count', 5);
    FlowFieldCache::invalidate(TestCustomer::class, $customer->id, 'balance');

    expect(FlowFieldCache::get($customer, 'balance'))->toBeNull();
    expect(FlowFieldCache::get($customer, 'entry_count'))->toBe(5);
});

it('invalidateAll removes all fields', function () {
    $customer = TestCustomer::create(['name' => 'Test']);

    FlowFieldCache::put($customer, 'balance', 100);
    FlowFieldCache::put($customer, 'entry_count', 5);
    FlowFieldCache::invalidateAll(TestCustomer::class, $customer->id);

    expect(FlowFieldCache::get($customer, 'balance'))->toBeNull();
    expect(FlowFieldCache::get($customer, 'entry_count'))->toBeNull();
});

it('preserves decimal precision', function () {
    $customer = TestCustomer::create(['name' => 'Test']);
    FlowFieldCache::put($customer, 'balance', 1234.56789);

    expect(FlowFieldCache::get($customer, 'balance'))->toBe(1234.56789);
});

it('handles boolean values correctly', function () {
    $customer = TestCustomer::create(['name' => 'Test']);

    FlowFieldCache::put($customer, 'has_entries', true);
    expect(FlowFieldCache::get($customer, 'has_entries'))->toBeTrue();

    FlowFieldCache::put($customer, 'has_entries', false);
    expect(FlowFieldCache::get($customer, 'has_entries'))->toBeFalse();
});
