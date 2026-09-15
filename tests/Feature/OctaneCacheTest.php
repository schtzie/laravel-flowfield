<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Support\FlowFieldQueryTracker;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;

beforeEach(function () {
    FlowFieldCache::resetStaticState();
    FlowFieldQueryTracker::reset();
});

// ---------------------------------------------------------------------------
// Octane static state reset
// ---------------------------------------------------------------------------

it('resetStaticState allows fresh tag detection on next access', function () {
    $customer = TestCustomer::create(['name' => 'Test']);

    // Create entry first, then access balance so 100 is cached
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $firstAccess = (float) $customer->balance;
    expect($firstAccess)->toBe(100.0);

    // After reset, the L2 cache still holds the value
    FlowFieldCache::resetStaticState();

    // Re-access should still work (served from L2)
    $freshCustomer = TestCustomer::find($customer->id);
    expect((float) $freshCustomer->balance)->toBe(100.0);
});

it('resetStaticState clears the Octane L1 cache', function () {
    config(['flowfield.cache.octane_l1' => true]);

    $customer = TestCustomer::create(['name' => 'L1 Test']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 250, 'type' => 'invoice',
    ]));

    $customer->balance; // Primes L1 + L2

    // Verify L2 cache is populated
    $key = FlowFieldCache::buildKey($customer, 'balance');
    expect(Cache::store('array')->get($key))->not->toBeNull();

    FlowFieldCache::resetStaticState(); // Clears L1 but not L2
    config(['flowfield.cache.octane_l1' => false]);

    // L2 still has value
    expect((float) Cache::store('array')->get($key))->toBe(250.0);
});

// ---------------------------------------------------------------------------
// Octane L1 two-tier caching
// ---------------------------------------------------------------------------

it('L1 cache serves value without hitting L2 store', function () {
    config(['flowfield.cache.octane_l1' => true]);

    $customer = TestCustomer::create(['name' => 'L1 Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 500, 'type' => 'invoice',
    ]));

    $customer->balance; // Prime L1 + L2

    // Now delete from L2 — L1 should still serve it
    Cache::store('array')->forget(FlowFieldCache::buildKey($customer, 'balance'));

    expect((float) $customer->balance)->toBe(500.0);

    config(['flowfield.cache.octane_l1' => false]);
});

it('L1 is populated when put() is called', function () {
    config(['flowfield.cache.octane_l1' => true]);

    $customer = TestCustomer::create(['name' => 'L1 Put Corp']);
    FlowFieldCache::put($customer, 'balance', 999.0);

    expect((float) $customer->balance)->toBe(999.0);

    config(['flowfield.cache.octane_l1' => false]);
});

it('L1 is invalidated when FlowFieldCache::invalidate is called', function () {
    config(['flowfield.cache.octane_l1' => true]);

    $customer = TestCustomer::create(['name' => 'L1 Invalidate Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $customer->balance; // Prime L1 + L2

    FlowFieldCache::invalidate(TestCustomer::class, $customer->id, 'balance');

    // After invalidation, next access recalculates from DB
    $defs = $customer->getFlowFieldDefinitions();
    $value = FlowFieldCache::remember($customer, 'balance', $defs['balance']);

    expect((float) $value)->toBe(100.0);

    config(['flowfield.cache.octane_l1' => false]);
});

// ---------------------------------------------------------------------------
// Query deduplication tracker
// ---------------------------------------------------------------------------

it('FlowFieldQueryTracker has/set/get round-trip works', function () {
    FlowFieldQueryTracker::reset();

    expect(FlowFieldQueryTracker::has('flowfield:test:1:balance'))->toBeFalse();

    config(['flowfield.query_deduplication.enabled' => true]);
    FlowFieldQueryTracker::set('flowfield:test:1:balance', 123.45);

    expect(FlowFieldQueryTracker::has('flowfield:test:1:balance'))->toBeTrue();
    expect(FlowFieldQueryTracker::get('flowfield:test:1:balance'))->toBe(123.45);

    config(['flowfield.query_deduplication.enabled' => false]);
});

it('FlowFieldQueryTracker does not store when deduplication disabled', function () {
    config(['flowfield.query_deduplication.enabled' => false]);
    FlowFieldQueryTracker::reset();

    FlowFieldQueryTracker::set('flowfield:test:1:balance', 42);

    expect(FlowFieldQueryTracker::has('flowfield:test:1:balance'))->toBeFalse();
});

it('FlowFieldQueryTracker reset clears all entries', function () {
    config(['flowfield.query_deduplication.enabled' => true]);
    FlowFieldQueryTracker::set('a', 1);
    FlowFieldQueryTracker::set('b', 2);

    expect(FlowFieldQueryTracker::keys())->toHaveCount(2);

    FlowFieldQueryTracker::reset();

    expect(FlowFieldQueryTracker::keys())->toHaveCount(0);

    config(['flowfield.query_deduplication.enabled' => false]);
});

// ---------------------------------------------------------------------------
// Auto driver detection (ServiceProvider logic)
// ---------------------------------------------------------------------------

it('resetTagsCache forces re-detection of tag support', function () {
    FlowFieldCache::resetTagsCache();

    $customer = TestCustomer::create(['name' => 'Tags Test Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 50, 'type' => 'invoice',
    ]));

    // Simply verify no exception on access after tags cache reset
    expect((float) $customer->balance)->toBe(50.0);
});

it('filter-aware cache key includes filter suffix', function () {
    $customer = TestCustomer::create(['name' => 'Filter Key Corp']);

    $plainKey = FlowFieldCache::buildKey($customer, 'balance');
    $filteredKey = FlowFieldCache::buildFilterAwareKey($customer, 'balance', [
        'posting_date' => ['2026-01-01', '2026-12-31'],
    ]);

    expect($filteredKey)->not->toBe($plainKey);
    expect($filteredKey)->toContain('posting_date=2026-01-01..2026-12-31');
});

it('filter-aware cache key is stable across calls', function () {
    $customer = TestCustomer::create(['name' => 'Stable Key Corp']);

    $key1 = FlowFieldCache::buildFilterAwareKey($customer, 'balance', ['dept' => 'IT']);
    $key2 = FlowFieldCache::buildFilterAwareKey($customer, 'balance', ['dept' => 'IT']);

    expect($key1)->toBe($key2);
});

// ---------------------------------------------------------------------------
// Octane Worker Boot & L1 Limits
// ---------------------------------------------------------------------------

it('l1_cache.max_entries evicts oldest entries when exceeded', function () {
    config(['flowfield.cache.octane_l1' => true]);
    config(['flowfield.octane.l1_cache.max_entries' => 2]);

    $c1 = TestCustomer::create(['name' => 'C1']);
    $c2 = TestCustomer::create(['name' => 'C2']);
    $c3 = TestCustomer::create(['name' => 'C3']);

    FlowFieldCache::put($c1, 'balance', 100);
    FlowFieldCache::put($c2, 'balance', 200);
    FlowFieldCache::put($c3, 'balance', 300);

    $k1 = FlowFieldCache::buildKey($c1, 'balance');
    $k2 = FlowFieldCache::buildKey($c2, 'balance');
    $k3 = FlowFieldCache::buildKey($c3, 'balance');

    // Only the last 2 should remain
    $reflection = new ReflectionClass(FlowFieldCache::class);
    $l1Property = $reflection->getProperty('octaneL1');
    $l1Property->setAccessible(true);
    $l1State = $l1Property->getValue();

    expect(array_key_exists($k1, $l1State))->toBeFalse();
    expect(array_key_exists($k2, $l1State))->toBeTrue();
    expect(array_key_exists($k3, $l1State))->toBeTrue();

    config(['flowfield.cache.octane_l1' => false]);
});

it('preload_models warms cache on WorkerStarting event', function () {
    config(['flowfield.octane.preload_models' => [TestCustomer::class]]);

    $customer = TestCustomer::create(['name' => 'Preload Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 888, 'type' => 'invoice',
    ]));

    // Ensure L2 cache is empty
    $key = FlowFieldCache::buildKey($customer, 'balance');
    Cache::store('array')->forget($key);
    expect(Cache::store('array')->get($key))->toBeNull();

    // Trigger WorkerStarting
    $listener = new Schtzie\FlowField\Listeners\OctaneFlowFieldListener();
    $listener->handleWorkerStarting(new stdClass());

    // Verify cache was warmed
    expect((float) Cache::store('array')->get($key))->toBe(888.0);
});
