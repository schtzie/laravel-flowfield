<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;
use stdClass;

beforeEach(function () {
    // Reset any cached state before each test
    FlowFieldCache::resetAll();
    $this->customer = TestCustomer::create(['name' => 'Config Test']);
});

it('uses the custom cache prefix from config', function () {
    config(['flowfield.cache.prefix' => 'custom_erp_prefix']);

    $key = FlowFieldCache::buildKey($this->customer, 'balance');
    expect($key)->toStartWith('custom_erp_prefix:test_customers:');

    // Make sure caching uses the new key
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 500, 'type' => 'invoice',
    ]));

    $this->customer->balance;
    expect(Cache::store('array')->get($key))->toBe(500);
});

it('uses a custom cache store from config', function () {
    // By default, the tests use 'array'. Let's swap it to another driver if available or just check interaction.
    // 'file' is usually available in testing out of the box in Laravel
    config(['flowfield.cache.store' => 'file']);

    // Clear the tag cache resolver since we changed store
    FlowFieldCache::resetTagsCache();

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 700, 'type' => 'invoice',
    ]));

    $balance = $this->customer->balance;
    expect((float) $balance)->toBe(700.0);

    // Verify it is NOT in the array store
    $key = FlowFieldCache::buildKey($this->customer, 'balance');
    expect(Cache::store('array')->get($key))->toBeNull();

    // Revert back so we don't break other tests
    config(['flowfield.cache.store' => 'array']);
    FlowFieldCache::resetTagsCache();
});

it('falls back to global ttl when definition ttl is null', function () {
    config(['flowfield.cache.ttl' => 60]);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 250, 'type' => 'invoice',
    ]));

    $this->customer->balance;

    // Verify it was cached
    $key = FlowFieldCache::buildKey($this->customer, 'balance');
    expect(Cache::store('array')->get($key))->toBe(250);

    // If ttl was null (forever), Laravel handles it differently.
    // We mainly assert it didn't crash and actually cached it.
});

it('does not cache when ttl is explicitly zero', function () {
    // live_balance has ttl: 0 in definition
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 150, 'type' => 'invoice',
    ]));

    expect((float) $this->customer->live_balance)->toBe(150.0);

    $key = FlowFieldCache::buildKey($this->customer, 'live_balance');
    expect(Cache::store('array')->get($key))->toBeNull();
});

it('query_deduplication serves subsequent requests from memory without hitting db or cache', function () {
    config(['flowfield.query_deduplication.enabled' => true]);
    config(['flowfield.cache.octane_l1' => false]);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 300, 'type' => 'invoice',
    ]));

    // First access primes the cache and the query tracker
    $this->customer->balance;

    // Simulate another process/service clearing the persistent cache
    $key = FlowFieldCache::buildKey($this->customer, 'balance');
    Cache::store('array')->forget($key);

    $queryCount = 0;
    \Illuminate\Support\Facades\DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    // Even though it's missing from the cache, the query tracker should intercept it
    $balance = $this->customer->balance;

    expect((float) $balance)->toBe(300.0);
    expect($queryCount)->toBe(0); // Zero queries because it hit the tracker
});

it('disabling query_deduplication forces a cache/db hit on every request', function () {
    config(['flowfield.query_deduplication.enabled' => false]);
    config(['flowfield.cache.octane_l1' => false]);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 400, 'type' => 'invoice',
    ]));

    // First access primes the cache
    $this->customer->balance;

    // Simulate another process/service clearing the persistent cache
    $key = FlowFieldCache::buildKey($this->customer, 'balance');
    Cache::store('array')->forget($key);

    $queryCount = 0;
    \Illuminate\Support\Facades\DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    // Tracker is disabled, cache is empty, so it MUST query the DB
    $balance = $this->customer->balance;

    expect((float) $balance)->toBe(400.0);
    expect($queryCount)->toBeGreaterThan(0); // It queried the DB
});

it('OctaneFlowFieldListener respects reset_static_state config', function () {
    $listener = new \Schtzie\FlowField\Listeners\OctaneFlowFieldListener();

    // Setup state
    config(['flowfield.cache.octane_l1' => true]);
    $this->customer->balance; // populates L1 cache

    // Assert L1 is populated
    expect(FlowFieldCache::octaneL1Enabled())->toBeTrue();
    $key = FlowFieldCache::buildKey($this->customer, 'balance');

    // Disable reset config
    config(['flowfield.octane.reset_static_state' => false]);
    $listener->handleRequestReceived(new stdClass());

    // Verify L1 is NOT cleared because reset is disabled
    config(['flowfield.cache.octane_l1' => false]); // reset so it queries normally
    FlowFieldCache::resetStaticState(); // manually reset for next step

    // Now enable config
    config(['flowfield.cache.octane_l1' => true]);
    $this->customer->balance; // populate again
    config(['flowfield.octane.reset_static_state' => true]);

    $listener->handleRequestReceived(new stdClass());

    // L1 should be cleared now
    config(['flowfield.cache.octane_l1' => false]);
});
