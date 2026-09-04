<?php

namespace Schtzie\FlowField\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Tests\Fixtures\TestItem;
use Schtzie\FlowField\Tests\Fixtures\TestStockMovement;
use Schtzie\FlowField\Tests\TestCase;

/**
 * Inventory FlowField Tests — Navision Item Ledger Entry analog
 *
 * In Business Central, every quantity on the Item card (Inventory, Purchases Qty.,
 * Sales Qty., etc.) is a SIFT-backed Sum FlowField. SIFT maintains pre-calculated
 * indexes on every write so reads are instant even with millions of ledger entries.
 *
 * Here we verify that our cache-backed implementation provides the same guarantees:
 * correct sums, correct isolation per SKU, automatic invalidation on every write,
 * and zero-query reads on cache hits.
 */
class InventoryFlowFieldTest extends TestCase
{
    protected TestItem $widget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->widget = TestItem::create(['sku' => 'WIDGET-001', 'name' => 'Blue Widget']);
    }

    // -------------------------------------------------------------------------
    // Sum FlowFields — SIFT analog
    // -------------------------------------------------------------------------

    public function test_inventory_quantity_reflects_initial_stock_receipt(): void
    {
        // Receive 100 units via purchase
        TestStockMovement::create([
            'item_id' => $this->widget->id,
            'movement_type' => 'purchase',
            'quantity' => 100,
            'posted_at' => now(),
        ]);

        $this->assertEquals(100, (float) $this->widget->inventory_quantity);
    }

    public function test_selling_stock_decrements_inventory_and_sold_quantity(): void
    {
        // Purchase 50 units
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 50, 'posted_at' => now(),
        ]));

        // Sell 20 units (stored as negative quantity in the ledger)
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'sale', 'quantity' => -20, 'posted_at' => now(),
        ]));

        // inventory_quantity = 50 + (-20) = 30
        $this->assertEquals(30, (float) $this->widget->inventory_quantity);
        // sold_quantity reflects only sale movements
        $this->assertEquals(-20, (float) $this->widget->sold_quantity);
        // purchased_quantity reflects only purchase movements
        $this->assertEquals(50, (float) $this->widget->purchased_quantity);
    }

    public function test_adjustment_quantity_is_isolated_from_other_movement_types(): void
    {
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 100, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'adjustment', 'quantity' => -5, 'posted_at' => now(),
        ]));

        // adjustment_quantity only sums adjustment rows
        $this->assertEquals(-5, (float) $this->widget->adjustment_quantity);
        // purchased_quantity unaffected by the adjustment
        $this->assertEquals(100, (float) $this->widget->purchased_quantity);
        // overall inventory reflects both
        $this->assertEquals(95, (float) $this->widget->inventory_quantity);
    }

    public function test_inventory_can_go_negative_backorder_scenario(): void
    {
        // Ship 30 units before the purchase receipt arrives (backorder / negative inventory)
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'sale', 'quantity' => -30, 'posted_at' => now(),
        ]));

        $this->assertEquals(-30, (float) $this->widget->inventory_quantity);
    }

    public function test_zero_balance_when_purchases_equal_sales(): void
    {
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 50, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'sale', 'quantity' => -50, 'posted_at' => now(),
        ]));

        // Exactly zero — not null
        $this->assertEquals(0, (float) $this->widget->inventory_quantity);
    }

    // -------------------------------------------------------------------------
    // Count FlowField
    // -------------------------------------------------------------------------

    public function test_movement_count_tracks_all_movement_types(): void
    {
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 10, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'sale', 'quantity' => -3, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'adjustment', 'quantity' => 1, 'posted_at' => now(),
        ]));

        $this->assertEquals(3, $this->widget->movement_count);
    }

    // -------------------------------------------------------------------------
    // Max FlowField — "Last Transaction Date"
    // -------------------------------------------------------------------------

    public function test_last_transaction_date_returns_most_recent_posting(): void
    {
        $older = now()->subDays(5)->toDateTimeString();
        $newer = now()->toDateTimeString();

        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 10, 'posted_at' => $older,
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 5, 'posted_at' => $newer,
        ]));

        $this->assertEquals($newer, $this->widget->last_transaction_date);
    }

    // -------------------------------------------------------------------------
    // Exists FlowField
    // -------------------------------------------------------------------------

    public function test_has_stock_movements_is_false_for_new_item(): void
    {
        $this->assertFalse($this->widget->has_stock_movements);
    }

    public function test_has_stock_movements_is_true_after_first_receipt(): void
    {
        TestStockMovement::create([
            'item_id' => $this->widget->id,
            'movement_type' => 'purchase',
            'quantity' => 1,
            'posted_at' => now(),
        ]);

        $freshItem = TestItem::find($this->widget->id);
        $this->assertTrue($freshItem->has_stock_movements);
    }

    // -------------------------------------------------------------------------
    // Cache behaviour
    // -------------------------------------------------------------------------

    public function test_second_inventory_read_hits_cache_with_zero_queries(): void
    {
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 75, 'posted_at' => now(),
        ]));

        // Prime cache
        $this->widget->inventory_quantity;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $result = $this->widget->inventory_quantity;

        $this->assertEquals(75, (float) $result);
        $this->assertEquals(0, $queryCount, 'Second read should be served from cache with zero DB queries');
    }

    public function test_creating_a_movement_invalidates_inventory_cache(): void
    {
        // Prime inventory cache at 0
        $this->widget->inventory_quantity;
        $cacheKey = "flowfield:test_items:{$this->widget->id}:inventory_quantity";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        // Triggering event invalidates cache
        TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 50, 'posted_at' => now(),
        ]);

        $this->assertNull(Cache::store('array')->get($cacheKey));
    }

    public function test_updating_quantity_invalidates_inventory_cache(): void
    {
        $movement = TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 50, 'posted_at' => now(),
        ]));

        $this->widget->calcFlowFields('inventory_quantity');
        $cacheKey = "flowfield:test_items:{$this->widget->id}:inventory_quantity";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        $movement->update(['quantity' => 80]);

        $this->assertNull(Cache::store('array')->get($cacheKey));
        // Fresh read reflects the updated quantity
        $this->assertEquals(80, (float) $this->widget->inventory_quantity);
    }

    public function test_soft_deleting_a_movement_reversal_invalidates_cache(): void
    {
        $movement = TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 60, 'posted_at' => now(),
        ]));

        // Cache with both movements present
        $this->widget->calcFlowFields('inventory_quantity');
        $cacheKey = "flowfield:test_items:{$this->widget->id}:inventory_quantity";
        $this->assertEquals(60, (float) Cache::store('array')->get($cacheKey));

        // Reverse / soft-delete the movement
        $movement->delete();

        // Cache must be invalidated
        $this->assertNull(Cache::store('array')->get($cacheKey));
        // Fresh read reflects only remaining movements (none here → 0)
        $this->assertEquals(0, (float) $this->widget->inventory_quantity);
    }

    // -------------------------------------------------------------------------
    // Multi-SKU isolation — FlowFields must not leak between Items
    // -------------------------------------------------------------------------

    public function test_flowfields_are_isolated_per_sku(): void
    {
        $gadget = TestItem::create(['sku' => 'GADGET-002', 'name' => 'Red Gadget']);

        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 100, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $gadget->id, 'movement_type' => 'purchase', 'quantity' => 25, 'posted_at' => now(),
        ]));

        $this->assertEquals(100, (float) $this->widget->inventory_quantity);
        $this->assertEquals(25, (float) $gadget->inventory_quantity);
    }

    // -------------------------------------------------------------------------
    // Bulk warm via withFlowFields scope
    // -------------------------------------------------------------------------

    public function test_with_flow_fields_scope_pre_warms_cache_for_all_items(): void
    {
        $gadget = TestItem::create(['sku' => 'GADGET-002', 'name' => 'Red Gadget']);

        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 40, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $gadget->id, 'movement_type' => 'purchase', 'quantity' => 15, 'posted_at' => now(),
        ]));

        // Bulk warm via scope (SIFT equivalent: pre-calculate all at once)
        TestItem::withFlowFields('inventory_quantity')->get();

        $widgetKey = "flowfield:test_items:{$this->widget->id}:inventory_quantity";
        $gadgetKey = "flowfield:test_items:{$gadget->id}:inventory_quantity";

        $this->assertEquals(40, (float) Cache::store('array')->get($widgetKey));
        $this->assertEquals(15, (float) Cache::store('array')->get($gadgetKey));
    }

    // -------------------------------------------------------------------------
    // orderByFlowField — sort items by stock level
    // -------------------------------------------------------------------------

    public function test_order_by_flow_field_sorts_items_by_inventory_descending(): void
    {
        $gadget = TestItem::create(['sku' => 'GADGET-002', 'name' => 'Red Gadget']);

        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $this->widget->id, 'movement_type' => 'purchase', 'quantity' => 10, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $gadget->id, 'movement_type' => 'purchase', 'quantity' => 999, 'posted_at' => now(),
        ]));

        $ordered = TestItem::orderByFlowField('inventory_quantity', 'desc')->pluck('sku')->toArray();

        $this->assertEquals('GADGET-002', $ordered[0]);
        $this->assertEquals('WIDGET-001', $ordered[1]);
    }
}
