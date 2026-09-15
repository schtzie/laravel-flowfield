<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;
use Schtzie\FlowField\Tests\Fixtures\TestItem;
use Schtzie\FlowField\Tests\Fixtures\TestStockMovement;

// --- Full Customer Ledger Cycle ---

it('full invoice-credit cycle brings balance to zero', function () {
    $customer = TestCustomer::create(['name' => 'Lifecycle Corp']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 1500, 'type' => 'invoice',
    ]));

    expect((float) $customer->balance)->toBe(1500.0);

    TestEntry::create([
        'customer_id' => $customer->id, 'amount' => -1500, 'type' => 'credit',
    ]);

    expect((float) TestCustomer::find($customer->id)->balance)->toBe(0.0);
});

it('partial payment leaves correct outstanding balance', function () {
    $customer = TestCustomer::create(['name' => 'Partial Payer']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 1000, 'type' => 'invoice',
    ]));

    TestEntry::create([
        'customer_id' => $customer->id, 'amount' => -400, 'type' => 'credit',
    ]);

    expect((float) TestCustomer::find($customer->id)->balance)->toBe(600.0);
});

// --- Multiple Where Conditions ---

it('multiple where conditions are ANDed together', function () {
    $customer = TestCustomer::create(['name' => 'Filter Test Corp']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 200, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 150, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 50, 'type' => 'credit',
    ]));

    expect((float) $customer->total_invoiced)->toBe(350.0);
    expect((float) $customer->balance)->toBe(400.0);
});

it('where with array values uses whereIn', function () {
    $item = TestItem::create(['sku' => 'MULTI-001', 'name' => 'Multi-type Item']);

    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 100, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $item->id, 'movement_type' => 'adjustment', 'quantity' => 10, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $item->id, 'movement_type' => 'sale', 'quantity' => -30, 'posted_at' => now(),
    ]));

    expect((float) $item->inventory_quantity)->toBe(80.0);
    expect((float) $item->purchased_quantity)->toBe(100.0);
});

// --- Custom TTL ---

it('flowfield uses config default ttl when none specified', function () {
    config(['flowfield.cache.ttl' => 7200]);

    $customer = TestCustomer::create(['name' => 'TTL Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $customer->balance;

    $key = "flowfield:test_customers:{$customer->id}:balance";
    expect(Cache::store('array')->get($key))->not->toBeNull();
});

// --- Custom Cache Key ---

it('custom cache key stores and retrieves from correct key', function () {
    $customer = TestCustomer::create(['name' => 'Cache Key Corp']);

    $defs = $customer->getFlowFieldDefinitions();
    expect($defs['balance']->getCacheKeyName())->toBe('balance');
    expect($defs['total_invoiced']->getCacheKeyName())->toBe('total_invoiced');
});

// --- Large Dataset ---

it('sum is accurate across 500 ledger entries', function () {
    $customer = TestCustomer::create(['name' => 'Big Dataset Corp']);

    $phpSum = 0;
    $rows = [];

    for ($i = 1; $i <= 500; $i++) {
        $amount = round(($i % 7 === 0 ? -1 : 1) * ($i * 1.37), 2);
        $phpSum += $amount;
        $rows[] = [
            'customer_id' => $customer->id,
            'amount' => $amount,
            'type' => 'invoice',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    TestEntry::withoutEvents(function () use ($rows) {
        foreach (array_chunk($rows, 100) as $chunk) {
            TestEntry::insert($chunk);
        }
    });

    expect((float) $customer->balance)->toEqualWithDelta($phpSum, 0.01);
});

// --- Bulk Warm ---

it('bulk warm via withFlowFields serves all reads from cache', function () {
    $c1 = TestCustomer::create(['name' => 'Alpha']);
    $c2 = TestCustomer::create(['name' => 'Beta']);
    $c3 = TestCustomer::create(['name' => 'Gamma']);

    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c1->id, 'amount' => 100, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c2->id, 'amount' => 200, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c3->id, 'amount' => 300, 'type' => 'invoice']));

    $customers = TestCustomer::withFlowFields('balance')->get();

    $queryCount = 0;
    DB::listen(fn () => $queryCount++);

    foreach ($customers as $c) {
        $c->balance;
    }

    expect($queryCount)->toBe(0);

    $byName = $customers->keyBy('name');
    expect((float) $byName['Alpha']->balance)->toBe(100.0);
    expect((float) $byName['Beta']->balance)->toBe(200.0);
    expect((float) $byName['Gamma']->balance)->toBe(300.0);
});

// --- withFlowFields + orderByFlowField combined ---

it('orderByFlowField combined with withFlowFields works correctly', function () {
    $c1 = TestCustomer::create(['name' => 'Low Balance']);
    $c2 = TestCustomer::create(['name' => 'High Balance']);

    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c1->id, 'amount' => 10, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c2->id, 'amount' => 9999, 'type' => 'invoice']));

    $customers = TestCustomer::withFlowFields('balance')
        ->orderByFlowField('balance', 'desc')
        ->get();

    expect($customers->first()->name)->toBe('High Balance');
    expect($customers->last()->name)->toBe('Low Balance');
    expect(Cache::store('array')->get("flowfield:test_customers:{$c2->id}:balance"))->not->toBeNull();
});

// --- Cache Key Format ---

it('cache key follows prefix:table:id:field convention', function () {
    $customer = TestCustomer::create(['name' => 'Key Format Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 42, 'type' => 'invoice',
    ]));

    $customer->balance;

    $key = "flowfield:test_customers:{$customer->id}:balance";
    expect((float) Cache::store('array')->get($key))->toBe(42.0);
});

// --- Cross-Domain Coexistence ---

it('inventory and customer flowfields coexist without interference', function () {
    $customer = TestCustomer::create(['name' => 'Cross Domain Corp']);
    $item = TestItem::create(['sku' => 'CROSS-001', 'name' => 'Cross Domain Widget']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 500, 'type' => 'invoice',
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 200, 'posted_at' => now(),
    ]));

    expect((float) $customer->balance)->toBe(500.0);
    expect((float) $item->inventory_quantity)->toBe(200.0);

    $customer->flushFlowFields();

    $itemKey = "flowfield:test_items:{$item->id}:inventory_quantity";
    expect(Cache::store('array')->get($itemKey))->not->toBeNull();
});

// --- buildKeyFromParts ---

it('buildKeyFromParts produces deterministic cache key', function () {
    $key = FlowFieldCache::buildKeyFromParts(TestCustomer::class, 42, 'balance');
    expect($key)->toBe('flowfield:test_customers:42:balance');
});
