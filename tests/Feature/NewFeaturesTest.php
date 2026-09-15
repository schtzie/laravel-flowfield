<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;
use Schtzie\FlowField\Tests\Fixtures\TestItem;
use Schtzie\FlowField\Tests\Fixtures\TestStockMovement;

beforeEach(function () {
    $this->customer = TestCustomer::create(['name' => 'Feature Test Corp']);
});

// --- Feature 1: Lookup FlowField ---

it('lookup returns column value from related record', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'credit',
    ]));

    expect($this->customer->latest_entry_type)->toBe('credit');
});

it('lookup returns null when no related record exists', function () {
    expect($this->customer->latest_entry_type)->toBeNull();
});

it('lookup value is cached after first access', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'invoice',
    ]));

    $this->customer->latest_entry_type; // prime cache

    $queryCount = 0;
    DB::listen(fn () => $queryCount++);

    expect($this->customer->latest_entry_type)->toBe('invoice');
    expect($queryCount)->toBe(0);
});

it('lookup cache is invalidated when related record changes', function () {
    $entry = TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $this->customer->latest_entry_type;
    $key = "flowfield:test_customers:{$this->customer->id}:latest_entry_type";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    $entry->update(['type' => 'credit']);

    expect(Cache::store('array')->get($key))->toBeNull();
    expect(TestCustomer::find($this->customer->id)->latest_entry_type)->toBe('credit');
});

// --- Feature 2: Comparison operators ---

it('greater-than operator filters correctly', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => -50, 'type' => 'credit',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'invoice',
    ]));

    expect((float) $this->customer->positive_sum)->toBe(300.0);
    expect((float) $this->customer->balance)->toBe(250.0);
});

it('between operator counts entries in range', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => -50, 'type' => 'credit',
    ]));

    expect($this->customer->mid_range_entry_count)->toBe(1);
});

it('operator where cache is invalidated on write', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    expect((float) $this->customer->positive_sum)->toBe(100.0);
    $key = "flowfield:test_customers:{$this->customer->id}:positive_sum";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice']);

    expect(Cache::store('array')->get($key))->toBeNull();
    expect((float) TestCustomer::find($this->customer->id)->positive_sum)->toBe(150.0);
});

// --- Feature 3: whereNull / whereNotNull ---

it('whereNull counts only non-voided entries', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice', 'voided_at' => null,
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'invoice', 'voided_at' => null,
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice', 'voided_at' => now(),
    ]));

    expect($this->customer->active_entry_count)->toBe(2);
});

it('whereNotNull sums only voided entries', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 500, 'type' => 'invoice', 'voided_at' => null,
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 75, 'type' => 'invoice', 'voided_at' => now(),
    ]));

    expect((float) $this->customer->voided_sum)->toBe(75.0);
});

it('voiding an entry shifts it from active to voided aggregates', function () {
    $entry = TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 300, 'type' => 'invoice', 'voided_at' => null,
    ]));

    expect($this->customer->active_entry_count)->toBe(1);
    expect((float) $this->customer->voided_sum)->toBe(0.0);

    $entry->update(['voided_at' => now()]);

    $fresh = TestCustomer::find($this->customer->id);
    expect($fresh->active_entry_count)->toBe(0);
    expect((float) $fresh->voided_sum)->toBe(300.0);
});

// --- Feature 4: No-cache mode (ttl: 0) ---

it('no-cache mode never stores value in cache', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    expect((float) $this->customer->live_balance)->toBe(100.0);
    $key = "flowfield:test_customers:{$this->customer->id}:live_balance";
    expect(Cache::store('array')->get($key))->toBeNull();
});

it('no-cache mode always returns fresh value from db', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    expect((float) $this->customer->live_balance)->toBe(100.0);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice',
    ]));

    expect((float) $this->customer->live_balance)->toBe(150.0);
});

it('no-cache mode does not interfere with cached fields', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $this->customer->balance;
    $this->customer->live_balance;

    $balanceKey = "flowfield:test_customers:{$this->customer->id}:balance";
    $liveKey = "flowfield:test_customers:{$this->customer->id}:live_balance";

    expect(Cache::store('array')->get($balanceKey))->not->toBeNull();
    expect(Cache::store('array')->get($liveKey))->toBeNull();
});

// --- Feature 5: getFlowFieldValues() ---

it('getFlowFieldValues returns all fields by default', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 250, 'type' => 'invoice', 'voided_at' => null,
    ]));

    $values = $this->customer->getFlowFieldValues();

    expect($values)->toBeArray();
    expect($values)->toHaveKeys(['balance', 'entry_count', 'has_entries', 'positive_sum', 'active_entry_count', 'live_balance']);
});

it('getFlowFieldValues returns correct computed values', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 300, 'type' => 'invoice', 'voided_at' => null,
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => -100, 'type' => 'credit', 'voided_at' => null,
    ]));

    $values = $this->customer->getFlowFieldValues('balance', 'entry_count', 'has_entries');

    expect((float) $values['balance'])->toBe(200.0);
    expect($values['entry_count'])->toBe(2);
    expect($values['has_entries'])->toBeTrue();
});

it('getFlowFieldValues accepts specific field names', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice', 'voided_at' => null,
    ]));

    $values = $this->customer->getFlowFieldValues('balance', 'total_invoiced');

    expect($values)->toHaveKeys(['balance', 'total_invoiced']);
    expect($values)->not->toHaveKey('entry_count');
    expect($values)->not->toHaveKey('has_entries');
});

it('getFlowFieldValues ignores unknown field names', function () {
    $values = $this->customer->getFlowFieldValues('balance', 'non_existent_field');

    expect($values)->toHaveKey('balance');
    expect($values)->not->toHaveKey('non_existent_field');
});

it('getFlowFieldValues hits cache for pre-warmed fields', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice', 'voided_at' => null,
    ]));

    $this->customer->balance;

    $queryCount = 0;
    DB::listen(fn () => $queryCount++);

    $values = $this->customer->getFlowFieldValues('balance');

    expect((float) $values['balance'])->toBe(100.0);
    expect($queryCount)->toBe(0);
});

// --- Feature 6: distinct count ---

it('distinct count counts unique values not total rows', function () {
    $item = TestItem::create(['sku' => 'DIST-001', 'name' => 'Distinct Item']);

    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 100, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 50, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $item->id, 'movement_type' => 'sale', 'quantity' => -30, 'posted_at' => now(),
    ]));

    expect($item->movement_count)->toBe(3);
    expect($item->distinct_movement_type_count)->toBe(2);
});

it('distinct count returns one when all movements are same type', function () {
    $item = TestItem::create(['sku' => 'DIST-002', 'name' => 'Same Type Item']);

    foreach ([10, 20, 30] as $qty) {
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => $qty, 'posted_at' => now(),
        ]));
    }

    expect($item->movement_count)->toBe(3);
    expect($item->distinct_movement_type_count)->toBe(1);
});

it('distinct count reaches maximum when all types present', function () {
    $item = TestItem::create(['sku' => 'DIST-003', 'name' => 'All Types Item']);

    foreach (['purchase' => 100, 'sale' => -20, 'adjustment' => 5] as $type => $qty) {
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => $type, 'quantity' => $qty, 'posted_at' => now(),
        ]));
    }

    expect($item->distinct_movement_type_count)->toBe(3);
});

it('distinct count is cached after first access', function () {
    $item = TestItem::create(['sku' => 'DIST-004', 'name' => 'Cache Item']);

    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 1, 'posted_at' => now(),
    ]));

    $item->distinct_movement_type_count;

    $queryCount = 0;
    DB::listen(fn () => $queryCount++);

    expect($item->distinct_movement_type_count)->toBe(1);
    expect($queryCount)->toBe(0);
});

// --- Definition metadata ---

it('distinct flag is reflected in definition', function () {
    $item = TestItem::create(['sku' => 'META-001', 'name' => 'Meta Item']);
    $defs = $item->getFlowFieldDefinitions();

    expect($defs['distinct_movement_type_count']->distinct)->toBeTrue();
    expect($defs['movement_count']->distinct)->toBeFalse();
});

it('lookup method is reflected in definition', function () {
    $defs = $this->customer->getFlowFieldDefinitions();

    expect($defs['latest_entry_type']->method)->toBe('lookup');
    expect($defs['latest_entry_type']->relation)->toBe('latestEntry');
    expect($defs['latest_entry_type']->column)->toBe('type');
});

it('no-cache ttl is reflected in definition', function () {
    $defs = $this->customer->getFlowFieldDefinitions();
    expect($defs['live_balance']->ttl)->toBe(0);
});
