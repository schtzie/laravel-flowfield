<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;

beforeEach(function () {
    $this->customer = TestCustomer::create(['name' => 'Acme Corp']);

    TestEntry::withoutEvents(function () {
        TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice']);
    });

    $this->customer->calcFlowFields('balance');
});

it('creating an entry invalidates the cache', function () {
    $key = "flowfield:test_customers:{$this->customer->id}:balance";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice']);

    expect(Cache::store('array')->get($key))->toBeNull();
});

it('updating an entry invalidates the cache', function () {
    $entry = TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice',
    ]));

    $this->customer->calcFlowFields('balance');
    $key = "flowfield:test_customers:{$this->customer->id}:balance";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    $entry->update(['amount' => 75]);

    expect(Cache::store('array')->get($key))->toBeNull();
});

it('deleting an entry invalidates the cache', function () {
    $entry = TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice',
    ]));

    $this->customer->calcFlowFields('balance');
    $key = "flowfield:test_customers:{$this->customer->id}:balance";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    $entry->forceDelete();

    expect(Cache::store('array')->get($key))->toBeNull();
});

it('updating irrelevant column does not invalidate cache', function () {
    $entry = TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice',
    ]));

    $this->customer->calcFlowFields('balance');
    $key = "flowfield:test_customers:{$this->customer->id}:balance";
    $cachedValue = Cache::store('array')->get($key);

    $entry->updated_at = now()->addHour();
    $entry->save();

    expect(Cache::store('array')->get($key))->toBe($cachedValue);
});

it('changing foreign key invalidates both parent caches', function () {
    $customer2 = TestCustomer::create(['name' => 'Other Corp']);

    $entry = TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice',
    ]));

    $this->customer->calcFlowFields('balance');
    $customer2->calcFlowFields('balance');

    $key1 = "flowfield:test_customers:{$this->customer->id}:balance";
    $key2 = "flowfield:test_customers:{$customer2->id}:balance";

    expect(Cache::store('array')->get($key1))->not->toBeNull();
    expect(Cache::store('array')->get($key2))->not->toBeNull();

    $entry->update(['customer_id' => $customer2->id]);

    expect(Cache::store('array')->get($key1))->toBeNull();
    expect(Cache::store('array')->get($key2))->toBeNull();
});

it('auto_warm recalculates cache after invalidation', function () {
    config(['flowfield.auto_warm' => true]);

    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'invoice']);

    $key = "flowfield:test_customers:{$this->customer->id}:balance";
    expect(Cache::store('array')->get($key))->not->toBeNull();
});

it('restoring soft deleted entry invalidates cache', function () {
    $entry = TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice',
    ]));

    $entry->delete();
    $this->customer->calcFlowFields('balance');

    $key = "flowfield:test_customers:{$this->customer->id}:balance";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    $entry->restore();

    expect(Cache::store('array')->get($key))->toBeNull();
});
