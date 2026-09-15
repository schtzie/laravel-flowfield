<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Tests\Fixtures\TestItem;
use Schtzie\FlowField\Tests\Fixtures\TestStockMovement;

beforeEach(function () {
    $this->widget = TestItem::create(['sku' => 'WIDGET-001', 'name' => 'Blue Widget']);
});

// --- Sum FlowFields (SIFT analog) ---

it('inventory_quantity reflects initial stock receipt', function () {
    TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase',
        'quantity' => 100, 'posted_at' => now(),
    ]);

    expect((float) $this->widget->inventory_quantity)->toBe(100.0);
});

it('selling stock decrements inventory and updates sold_quantity', function () {
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase',
        'quantity' => 50, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'sale',
        'quantity' => -20, 'posted_at' => now(),
    ]));

    expect((float) $this->widget->inventory_quantity)->toBe(30.0);
    expect((float) $this->widget->sold_quantity)->toBe(-20.0);
    expect((float) $this->widget->purchased_quantity)->toBe(50.0);
});

it('adjustment_quantity is isolated from other movement types', function () {
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase',
        'quantity' => 100, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'adjustment',
        'quantity' => -5, 'posted_at' => now(),
    ]));

    expect((float) $this->widget->adjustment_quantity)->toBe(-5.0);
    expect((float) $this->widget->purchased_quantity)->toBe(100.0);
    expect((float) $this->widget->inventory_quantity)->toBe(95.0);
});

it('inventory can go negative in backorder scenario', function () {
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'sale',
        'quantity' => -30, 'posted_at' => now(),
    ]));

    expect((float) $this->widget->inventory_quantity)->toBe(-30.0);
});

it('inventory is zero when purchases equal sales', function () {
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase',
        'quantity' => 50, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'sale',
        'quantity' => -50, 'posted_at' => now(),
    ]));

    expect((float) $this->widget->inventory_quantity)->toBe(0.0);
});

// --- Count FlowField ---

it('movement_count tracks all movement types', function () {
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 10, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'sale', 'quantity' => -3, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'adjustment', 'quantity' => 1, 'posted_at' => now(),
    ]));

    expect($this->widget->movement_count)->toBe(3);
});

// --- Max FlowField (Last Transaction Date) ---

it('last_transaction_date returns most recent posting', function () {
    $older = now()->subDays(5)->toDateTimeString();
    $newer = now()->toDateTimeString();

    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 10, 'posted_at' => $older,
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 5, 'posted_at' => $newer,
    ]));

    expect($this->widget->last_transaction_date)->toBe($newer);
});

// --- Exists FlowField ---

it('has_stock_movements is false for new item', function () {
    expect($this->widget->has_stock_movements)->toBeFalse();
});

it('has_stock_movements is true after first receipt', function () {
    TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase',
        'quantity' => 1, 'posted_at' => now(),
    ]);

    expect(TestItem::find($this->widget->id)->has_stock_movements)->toBeTrue();
});

// --- Cache behaviour ---

it('second inventory read hits cache with zero queries', function () {
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 75, 'posted_at' => now(),
    ]));
    $this->widget->inventory_quantity; // prime cache

    $queryCount = 0;
    DB::listen(fn () => $queryCount++);

    expect((float) $this->widget->inventory_quantity)->toBe(75.0);
    expect($queryCount)->toBe(0);
});

it('creating a movement invalidates inventory cache', function () {
    $this->widget->inventory_quantity;
    $key = "flowfield:test_items:{$this->widget->id}:inventory_quantity";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 50, 'posted_at' => now(),
    ]);

    expect(Cache::store('array')->get($key))->toBeNull();
});

it('updating quantity invalidates inventory cache', function () {
    $movement = TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 50, 'posted_at' => now(),
    ]));

    $this->widget->calcFlowFields('inventory_quantity');
    $key = "flowfield:test_items:{$this->widget->id}:inventory_quantity";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    $movement->update(['quantity' => 80]);

    expect(Cache::store('array')->get($key))->toBeNull();
    expect((float) $this->widget->inventory_quantity)->toBe(80.0);
});

it('soft deleting a movement invalidates cache', function () {
    $movement = TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 60, 'posted_at' => now(),
    ]));

    $this->widget->calcFlowFields('inventory_quantity');
    $key = "flowfield:test_items:{$this->widget->id}:inventory_quantity";
    expect((float) Cache::store('array')->get($key))->toBe(60.0);

    $movement->delete();

    expect(Cache::store('array')->get($key))->toBeNull();
    expect((float) $this->widget->inventory_quantity)->toBe(0.0);
});

// --- Multi-SKU isolation ---

it('flowfields are isolated per SKU', function () {
    $gadget = TestItem::create(['sku' => 'GADGET-002', 'name' => 'Red Gadget']);

    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 100, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $gadget->id, 'movement_type' => 'purchase', 'quantity' => 25, 'posted_at' => now(),
    ]));

    expect((float) $this->widget->inventory_quantity)->toBe(100.0);
    expect((float) $gadget->inventory_quantity)->toBe(25.0);
});

// --- Bulk warm via withFlowFields scope ---

it('withFlowFields scope pre-warms cache for all items', function () {
    $gadget = TestItem::create(['sku' => 'GADGET-002', 'name' => 'Red Gadget']);

    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 40, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $gadget->id, 'movement_type' => 'purchase', 'quantity' => 15, 'posted_at' => now(),
    ]));

    TestItem::withFlowFields('inventory_quantity')->get();

    expect((float) Cache::store('array')->get("flowfield:test_items:{$this->widget->id}:inventory_quantity"))->toBe(40.0);
    expect((float) Cache::store('array')->get("flowfield:test_items:{$gadget->id}:inventory_quantity"))->toBe(15.0);
});

// --- orderByFlowField ---

it('orderByFlowField sorts items by inventory descending', function () {
    $gadget = TestItem::create(['sku' => 'GADGET-002', 'name' => 'Red Gadget']);

    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 10, 'posted_at' => now(),
    ]));
    TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
        'item_id' => $gadget->id, 'movement_type' => 'purchase', 'quantity' => 999, 'posted_at' => now(),
    ]));

    $ordered = TestItem::orderByFlowField('inventory_quantity', 'desc')->pluck('sku')->toArray();

    expect($ordered[0])->toBe('GADGET-002');
    expect($ordered[1])->toBe('WIDGET-001');
});
