<?php

namespace Schtzie\FlowField\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Tests\Fixtures\TestPurchaseLine;
use Schtzie\FlowField\Tests\Fixtures\TestVendor;
use Schtzie\FlowField\Tests\TestCase;

/**
 * Vendor Ledger FlowField Tests — Navision Vendor Ledger Entry analog
 *
 * In Business Central, a Vendor card shows Accounts Payable figures that are
 * all Sum or Count FlowFields over the Vendor Ledger Entry table. The key
 * distinction is between OPEN entries (still owed) and CLOSED entries (paid).
 *
 * This test suite exercises conditional where-filtered FlowFields in realistic
 * AP lifecycle scenarios: open → paid status transitions, multi-vendor isolation,
 * re-assignment of a purchase line between vendors.
 */
class VendorLedgerFlowFieldTest extends TestCase
{
    protected TestVendor $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = TestVendor::create(['name' => 'Acme Supplies Ltd']);
    }

    // -------------------------------------------------------------------------
    // outstanding_amount — Sum with status='open' filter
    // -------------------------------------------------------------------------

    public function test_outstanding_amount_reflects_only_open_lines(): void
    {
        // Two open lines
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 500, 'status' => 'open',
        ]));
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 300, 'status' => 'open',
        ]));
        // One already paid — should NOT appear in outstanding
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 200, 'status' => 'paid',
        ]));

        $this->assertEquals(800, (float) $this->supplier->outstanding_amount);
    }

    public function test_paid_amount_reflects_only_paid_lines(): void
    {
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 1000, 'status' => 'open',
        ]));
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 400, 'status' => 'paid',
        ]));
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 600, 'status' => 'paid',
        ]));

        $this->assertEquals(1000, (float) $this->supplier->paid_amount);
    }

    public function test_paying_a_line_shifts_amount_from_outstanding_to_paid(): void
    {
        $line = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 750, 'status' => 'open',
        ]));

        // Before payment
        $this->assertEquals(750, (float) $this->supplier->outstanding_amount);
        $this->assertEquals(0, (float) $this->supplier->paid_amount);

        // Mark line as paid — triggers cache invalidation via InvalidatesFlowFields
        $line->update(['status' => 'paid']);

        // After payment — fresh values from DB
        $freshSupplier = TestVendor::find($this->supplier->id);
        $this->assertEquals(0, (float) $freshSupplier->outstanding_amount);
        $this->assertEquals(750, (float) $freshSupplier->paid_amount);
    }

    // -------------------------------------------------------------------------
    // open_order_count — Count with status='open' filter
    // -------------------------------------------------------------------------

    public function test_open_order_count_decrements_after_payment(): void
    {
        $line1 = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 100, 'status' => 'open',
        ]));
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 200, 'status' => 'open',
        ]));

        $this->assertEquals(2, $this->supplier->open_order_count);

        $line1->update(['status' => 'paid']);

        $freshSupplier = TestVendor::find($this->supplier->id);
        $this->assertEquals(1, $freshSupplier->open_order_count);
    }

    public function test_open_order_count_is_zero_when_all_lines_paid(): void
    {
        $line = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 500, 'status' => 'open',
        ]));

        $this->assertEquals(1, $this->supplier->open_order_count);

        $line->update(['status' => 'paid']);

        $freshSupplier = TestVendor::find($this->supplier->id);
        $this->assertEquals(0, $freshSupplier->open_order_count);
    }

    // -------------------------------------------------------------------------
    // has_open_orders — Exists with status='open' filter
    // -------------------------------------------------------------------------

    public function test_has_open_orders_is_true_when_open_lines_exist(): void
    {
        TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 250, 'status' => 'open',
        ]);

        $freshSupplier = TestVendor::find($this->supplier->id);
        $this->assertTrue($freshSupplier->has_open_orders);
    }

    public function test_has_open_orders_flips_to_false_when_last_open_line_is_paid(): void
    {
        $line = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 250, 'status' => 'open',
        ]));

        $this->assertTrue($this->supplier->has_open_orders);

        $line->update(['status' => 'paid']);

        $freshSupplier = TestVendor::find($this->supplier->id);
        $this->assertFalse($freshSupplier->has_open_orders);
    }

    public function test_has_open_orders_is_false_for_vendor_with_no_lines(): void
    {
        $this->assertFalse($this->supplier->has_open_orders);
    }

    // -------------------------------------------------------------------------
    // largest_order — Max FlowField
    // -------------------------------------------------------------------------

    public function test_largest_order_always_reflects_true_maximum(): void
    {
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 100, 'status' => 'open',
        ]));
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 9999, 'status' => 'open',
        ]));
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 50, 'status' => 'paid',
        ]));

        $this->assertEquals(9999, (float) $this->supplier->largest_order);
    }

    // -------------------------------------------------------------------------
    // average_order_value — Avg FlowField
    // -------------------------------------------------------------------------

    public function test_average_order_value_recalculates_on_new_line(): void
    {
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 100, 'status' => 'open',
        ]));
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 200, 'status' => 'open',
        ]));

        // avg = (100 + 200) / 2 = 150
        $this->assertEqualsWithDelta(150.0, (float) $this->supplier->average_order_value, 0.01);

        // Add a third line (cache invalidated by the create event)
        TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 300, 'status' => 'open',
        ]);

        // avg = (100 + 200 + 300) / 3 = 200
        $freshSupplier = TestVendor::find($this->supplier->id);
        $this->assertEqualsWithDelta(200.0, (float) $freshSupplier->average_order_value, 0.01);
    }

    // -------------------------------------------------------------------------
    // Multi-vendor isolation
    // -------------------------------------------------------------------------

    public function test_flowfields_are_isolated_per_vendor(): void
    {
        $otherSupplier = TestVendor::create(['name' => 'Beta Wholesalers']);

        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 1000, 'status' => 'open',
        ]));
        TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $otherSupplier->id, 'amount' => 250, 'status' => 'open',
        ]));

        $this->assertEquals(1000, (float) $this->supplier->outstanding_amount);
        $this->assertEquals(250, (float) $otherSupplier->outstanding_amount);
    }

    // -------------------------------------------------------------------------
    // Cross-vendor cache invalidation — re-assigning a line
    // -------------------------------------------------------------------------

    public function test_reassigning_purchase_line_invalidates_both_vendors_caches(): void
    {
        $otherSupplier = TestVendor::create(['name' => 'Beta Wholesalers']);

        $line = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 500, 'status' => 'open',
        ]));

        // Prime caches for both vendors
        $this->supplier->calcFlowFields('outstanding_amount');
        $otherSupplier->calcFlowFields('outstanding_amount');

        $cacheKey1 = "flowfield:test_vendors:{$this->supplier->id}:outstanding_amount";
        $cacheKey2 = "flowfield:test_vendors:{$otherSupplier->id}:outstanding_amount";

        $this->assertNotNull(Cache::store('array')->get($cacheKey1));
        $this->assertNotNull(Cache::store('array')->get($cacheKey2));

        // Move the line to the other vendor
        $line->update(['vendor_id' => $otherSupplier->id]);

        // Both caches must be invalidated
        $this->assertNull(Cache::store('array')->get($cacheKey1), 'Original vendor cache must be cleared');
        $this->assertNull(Cache::store('array')->get($cacheKey2), 'New vendor cache must be cleared');

        // Values must now be correct for both
        $freshSupplier1 = TestVendor::find($this->supplier->id);
        $freshSupplier2 = TestVendor::find($otherSupplier->id);

        $this->assertEquals(0, (float) $freshSupplier1->outstanding_amount);
        $this->assertEquals(500, (float) $freshSupplier2->outstanding_amount);
    }

    // -------------------------------------------------------------------------
    // Cache invalidation on relevant column change only
    // -------------------------------------------------------------------------

    public function test_updating_only_irrelevant_column_does_not_invalidate_cache(): void
    {
        $line = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
            'vendor_id' => $this->supplier->id, 'amount' => 300, 'status' => 'open',
        ]));

        $this->supplier->calcFlowFields('outstanding_amount');
        $cacheKey = "flowfield:test_vendors:{$this->supplier->id}:outstanding_amount";
        $cachedBefore = Cache::store('array')->get($cacheKey);

        // Touch timestamps only — no relevant column changed
        $line->updated_at = now()->addHour();
        $line->save();

        $this->assertEquals($cachedBefore, Cache::store('array')->get($cacheKey));
    }
}
