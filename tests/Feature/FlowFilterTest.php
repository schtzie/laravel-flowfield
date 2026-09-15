<?php

declare(strict_types=1);

use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;

// ---------------------------------------------------------------------------
// FlowFilter: setFlowFilter / withFlowFilter
// ---------------------------------------------------------------------------

it('setFlowFilter applies a scalar equality filter at runtime', function () {
    $customer = TestCustomer::create(['name' => 'Filter Corp']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 200, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'credit',
    ]));

    // Balance without filter = 300; with type=invoice filter = 200
    $filtered = clone $customer;
    $filtered->setFlowFilter('type', 'invoice', 'balance');

    expect((float) $filtered->balance)->toBe(200.0);
});

it('withFlowFilter is a fluent alias for setFlowFilter', function () {
    $customer = TestCustomer::create(['name' => 'Fluent Corp']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 500, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => -100, 'type' => 'credit',
    ]));

    $result = (clone $customer)->withFlowFilter('type', 'credit', 'balance')->balance;

    expect((float) $result)->toBe(-100.0);
});

it('clearFlowFilters removes all filters on a model', function () {
    $customer = TestCustomer::create(['name' => 'Clear Corp']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 300, 'type' => 'invoice',
    ]));

    $filtered = clone $customer;
    $filtered->setFlowFilter('type', 'invoice', 'balance');
    $filtered->clearFlowFilters();

    // After clearing, access unfiltered = 300
    expect((float) $filtered->balance)->toBe(300.0);
});

it('clearFlowFilters with field argument only clears that field', function () {
    $customer = TestCustomer::create(['name' => 'Partial Clear Corp']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 300, 'type' => 'invoice',
    ]));

    $filtered = clone $customer;
    $filtered->setFlowFilter('type', 'invoice', 'balance');
    $filtered->setFlowFilter('type', 'invoice', 'total_invoiced');

    $filtered->clearFlowFilters('balance');

    // balance filter cleared — should return full sum
    expect((float) $filtered->balance)->toBe(300.0);
    // total_invoiced filter still active — still scoped to invoices
    expect((float) $filtered->total_invoiced)->toBe(300.0);
});

it('FlowFilter with date range applies BETWEEN condition', function () {
    $customer = TestCustomer::create(['name' => 'Date Range Corp']);

    // Posted inside range
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice',
        'created_at' => '2026-03-15',
    ]));

    // Posted outside range
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 999, 'type' => 'invoice',
        'created_at' => '2025-12-01',
    ]));

    // Check unfiltered balance first
    expect((float) $customer->balance)->toBe(1099.0);

    // Filter-aware cache key prevents collision with unfiltered key
    $filteredKey = FlowFieldCache::buildFilterAwareKey($customer, 'balance', [
        'created_at' => ['2026-01-01', '2026-12-31'],
    ]);
    $plainKey = FlowFieldCache::buildKey($customer, 'balance');

    expect($filteredKey)->not->toBe($plainKey);
});

it('FlowFilter generates distinct cache keys per filter value', function () {
    $customer = TestCustomer::create(['name' => 'Key Isolation Corp']);

    $key1 = FlowFieldCache::buildFilterAwareKey($customer, 'balance', ['type' => 'invoice']);
    $key2 = FlowFieldCache::buildFilterAwareKey($customer, 'balance', ['type' => 'credit']);
    $plain = FlowFieldCache::buildKey($customer, 'balance');

    expect($key1)->not->toBe($key2);
    expect($key1)->not->toBe($plain);
    expect($key2)->not->toBe($plain);
});

it('model without filter returns same as model with cleared filter', function () {
    $customer = TestCustomer::create(['name' => 'Symmetry Corp']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 150, 'type' => 'invoice',
    ]));

    $noFilter = $customer->balance;

    $filtered = clone $customer;
    $filtered->setFlowFilter('type', 'invoice', 'balance');
    $filtered->clearFlowFilters('balance');
    $clearedFilter = $filtered->balance;

    expect((float) $noFilter)->toBe((float) $clearedFilter);
});
