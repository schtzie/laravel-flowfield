<?php

namespace Schtzie\FlowField\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;
use Schtzie\FlowField\Tests\Fixtures\TestItem;
use Schtzie\FlowField\Tests\Fixtures\TestStockMovement;
use Schtzie\FlowField\Tests\TestCase;

/**
 * End-to-end feature tests for all 6 new FlowField capabilities.
 *
 * Feature 1 — Lookup FlowField (Navision's 7th type)
 * Feature 2 — Comparison operators in where conditions (>, <, >=, <=, !=, like, between)
 * Feature 3 — whereNull / whereNotNull conditions
 * Feature 4 — No-cache mode (ttl: 0)
 * Feature 5 — getFlowFieldValues() array serialization
 * Feature 6 — distinct: true on count FlowFields
 */
class NewFeaturesTest extends TestCase
{
    protected TestCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = TestCustomer::create(['name' => 'Feature Test Corp']);
    }

    // =========================================================================
    // Feature 1 — Lookup FlowField
    // =========================================================================

    public function test_lookup_returns_column_value_from_related_record(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id,
            'amount' => 100,
            'type' => 'invoice',
        ]));
        // Add a newer entry so latestOfMany picks this one
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id,
            'amount' => 50,
            'type' => 'credit',
        ]));

        // latest_entry_type resolves via hasOne()->latestOfMany()
        $this->assertEquals('credit', $this->customer->latest_entry_type);
    }

    public function test_lookup_returns_null_when_no_related_record(): void
    {
        // Customer with no entries — lookup should return null
        $this->assertNull($this->customer->latest_entry_type);
    }

    public function test_lookup_value_is_cached_after_first_access(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id,
            'amount' => 200,
            'type' => 'invoice',
        ]));

        $this->customer->latest_entry_type; // prime cache

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $result = $this->customer->latest_entry_type;

        $this->assertEquals('invoice', $result);
        $this->assertEquals(0, $queryCount, 'Lookup value must be served from cache on second access');
    }

    public function test_lookup_cache_is_invalidated_when_related_record_changes(): void
    {
        $entry = TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id,
            'amount' => 100,
            'type' => 'invoice',
        ]));

        $this->customer->latest_entry_type; // prime cache
        $cacheKey = "flowfield:test_customers:{$this->customer->id}:latest_entry_type";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        $entry->update(['type' => 'credit']); // triggers cache invalidation

        $this->assertNull(Cache::store('array')->get($cacheKey));
        $fresh = TestCustomer::find($this->customer->id);
        $this->assertEquals('credit', $fresh->latest_entry_type);
    }

    // =========================================================================
    // Feature 2 — Comparison operators in where conditions
    // =========================================================================

    public function test_greater_than_operator_filters_correctly(): void
    {
        // Three entries: 100 (positive), -50 (negative), 200 (positive)
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => -50, 'type' => 'credit',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'invoice',
        ]));

        // positive_sum: sum where amount > 0 → 100 + 200 = 300
        $this->assertEquals(300, (float) $this->customer->positive_sum);
        // balance: sum of all → 100 + (-50) + 200 = 250
        $this->assertEquals(250, (float) $this->customer->balance);
    }

    public function test_between_operator_counts_entries_in_range(): void
    {
        // Three entries: 100 (in range 50–150), 200 (out of range), -50 (out of range)
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'invoice',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => -50, 'type' => 'credit',
        ]));

        // mid_range_entry_count: count where amount BETWEEN 50 AND 150 → 1 (only 100)
        $this->assertEquals(1, $this->customer->mid_range_entry_count);
    }

    public function test_operator_where_cache_is_invalidated_on_write(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));

        $this->assertEquals(100, (float) $this->customer->positive_sum);
        $cacheKey = "flowfield:test_customers:{$this->customer->id}:positive_sum";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice',
        ]);

        $this->assertNull(Cache::store('array')->get($cacheKey));
        $fresh = TestCustomer::find($this->customer->id);
        $this->assertEquals(150, (float) $fresh->positive_sum);
    }

    // =========================================================================
    // Feature 3 — whereNull / whereNotNull
    // =========================================================================

    public function test_where_null_counts_only_non_voided_entries(): void
    {
        // Two active entries (voided_at IS NULL)
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice', 'voided_at' => null,
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'invoice', 'voided_at' => null,
        ]));
        // One voided entry
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice', 'voided_at' => now(),
        ]));

        // active_entry_count: count where voided_at IS NULL → 2
        $this->assertEquals(2, $this->customer->active_entry_count);
    }

    public function test_where_not_null_sums_only_voided_entries(): void
    {
        // Active entries
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 500, 'type' => 'invoice', 'voided_at' => null,
        ]));
        // Voided entry
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 75, 'type' => 'invoice', 'voided_at' => now(),
        ]));

        // voided_sum: sum where voided_at IS NOT NULL → 75
        $this->assertEquals(75, (float) $this->customer->voided_sum);
    }

    public function test_voiding_an_entry_shifts_it_between_active_and_voided(): void
    {
        $entry = TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 300, 'type' => 'invoice', 'voided_at' => null,
        ]));

        $this->assertEquals(1, $this->customer->active_entry_count);
        $this->assertEquals(0, (float) $this->customer->voided_sum);

        // Void the entry — triggers cache invalidation
        $entry->update(['voided_at' => now()]);

        $fresh = TestCustomer::find($this->customer->id);
        $this->assertEquals(0, $fresh->active_entry_count);
        $this->assertEquals(300, (float) $fresh->voided_sum);
    }

    // =========================================================================
    // Feature 4 — No-cache mode (ttl: 0)
    // =========================================================================

    public function test_no_cache_mode_never_stores_value_in_cache(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));

        // Access the live_balance FlowField (ttl: 0)
        $value = $this->customer->live_balance;
        $this->assertEquals(100, (float) $value);

        // Must NOT be in the cache
        $cacheKey = "flowfield:test_customers:{$this->customer->id}:live_balance";
        $this->assertNull(Cache::store('array')->get($cacheKey),
            'No-cache FlowField (ttl: 0) must never write to the cache store'
        );
    }

    public function test_no_cache_mode_always_returns_fresh_value_from_db(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));

        $this->assertEquals(100, (float) $this->customer->live_balance);

        // Add another entry without invalidation events — bypasses cache entirely
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice',
        ]));

        // No invalidation happened, but live_balance must still return the fresh sum
        // because it skips the cache on every access
        $this->assertEquals(150, (float) $this->customer->live_balance);
    }

    public function test_no_cache_mode_does_not_interfere_with_cached_fields(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));

        // Access both cached (balance) and no-cache (live_balance)
        $this->customer->balance; // primes cache
        $this->customer->live_balance; // always fresh

        $balanceCacheKey = "flowfield:test_customers:{$this->customer->id}:balance";
        $liveCacheKey = "flowfield:test_customers:{$this->customer->id}:live_balance";

        $this->assertNotNull(Cache::store('array')->get($balanceCacheKey), 'Normal field must be cached');
        $this->assertNull(Cache::store('array')->get($liveCacheKey), 'No-cache field must not be cached');
    }

    // =========================================================================
    // Feature 5 — getFlowFieldValues()
    // =========================================================================

    public function test_get_flow_field_values_returns_all_fields_by_default(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 250, 'type' => 'invoice', 'voided_at' => null,
        ]));

        $values = $this->customer->getFlowFieldValues();

        $this->assertIsArray($values);
        $this->assertArrayHasKey('balance', $values);
        $this->assertArrayHasKey('entry_count', $values);
        $this->assertArrayHasKey('has_entries', $values);
        $this->assertArrayHasKey('positive_sum', $values);
        $this->assertArrayHasKey('active_entry_count', $values);
        $this->assertArrayHasKey('live_balance', $values);
    }

    public function test_get_flow_field_values_returns_correct_computed_values(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 300, 'type' => 'invoice', 'voided_at' => null,
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => -100, 'type' => 'credit', 'voided_at' => null,
        ]));

        $values = $this->customer->getFlowFieldValues('balance', 'entry_count', 'has_entries');

        $this->assertEquals(200, (float) $values['balance']);
        $this->assertEquals(2, $values['entry_count']);
        $this->assertTrue($values['has_entries']);
    }

    public function test_get_flow_field_values_accepts_specific_field_names(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice', 'voided_at' => null,
        ]));

        $values = $this->customer->getFlowFieldValues('balance', 'total_invoiced');

        $this->assertArrayHasKey('balance', $values);
        $this->assertArrayHasKey('total_invoiced', $values);
        $this->assertArrayNotHasKey('entry_count', $values);
        $this->assertArrayNotHasKey('has_entries', $values);
    }

    public function test_get_flow_field_values_ignores_unknown_field_names(): void
    {
        $values = $this->customer->getFlowFieldValues('balance', 'non_existent_field');

        $this->assertArrayHasKey('balance', $values);
        $this->assertArrayNotHasKey('non_existent_field', $values);
    }

    public function test_get_flow_field_values_hits_cache_for_pre_warmed_fields(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice', 'voided_at' => null,
        ]));

        $this->customer->balance; // pre-warm cache

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $values = $this->customer->getFlowFieldValues('balance');

        $this->assertEquals(100, (float) $values['balance']);
        $this->assertEquals(0, $queryCount, 'getFlowFieldValues must serve cached fields with zero queries');
    }

    // =========================================================================
    // Feature 6 — distinct: true on count
    // =========================================================================

    public function test_distinct_count_counts_unique_values_not_total_rows(): void
    {
        $item = TestItem::create(['sku' => 'DIST-001', 'name' => 'Distinct Item']);

        // Three movements: two purchases (same type) and one sale
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 100, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 50, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => 'sale', 'quantity' => -30, 'posted_at' => now(),
        ]));

        // movement_count (no distinct) → 3 total rows
        $this->assertEquals(3, $item->movement_count);
        // distinct_movement_type_count → 2 unique types (purchase, sale)
        $this->assertEquals(2, $item->distinct_movement_type_count);
    }

    public function test_distinct_count_returns_one_when_all_movements_same_type(): void
    {
        $item = TestItem::create(['sku' => 'DIST-002', 'name' => 'Same Type Item']);

        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 10, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 20, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 30, 'posted_at' => now(),
        ]));

        // 3 rows but only 1 distinct movement_type
        $this->assertEquals(3, $item->movement_count);
        $this->assertEquals(1, $item->distinct_movement_type_count);
    }

    public function test_distinct_count_reaches_maximum_when_all_types_present(): void
    {
        $item = TestItem::create(['sku' => 'DIST-003', 'name' => 'All Types Item']);

        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 100, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => 'sale', 'quantity' => -20, 'posted_at' => now(),
        ]));
        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => 'adjustment', 'quantity' => 5, 'posted_at' => now(),
        ]));

        // All 3 movement types present → distinct count = 3
        $this->assertEquals(3, $item->distinct_movement_type_count);
    }

    public function test_distinct_count_is_cached_after_first_access(): void
    {
        $item = TestItem::create(['sku' => 'DIST-004', 'name' => 'Cache Item']);

        TestStockMovement::withoutEvents(fn () => TestStockMovement::create([
            'item_id' => $item->id, 'movement_type' => 'purchase', 'quantity' => 1, 'posted_at' => now(),
        ]));

        $item->distinct_movement_type_count; // prime cache

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $result = $item->distinct_movement_type_count;

        $this->assertEquals(1, $result);
        $this->assertEquals(0, $queryCount, 'Distinct count must be served from cache on second access');
    }

    // =========================================================================
    // Definition metadata
    // =========================================================================

    public function test_distinct_flag_is_reflected_in_definition(): void
    {
        $item = TestItem::create(['sku' => 'META-001', 'name' => 'Meta Item']);
        $definitions = $item->getFlowFieldDefinitions();

        $this->assertTrue($definitions['distinct_movement_type_count']->distinct);
        $this->assertFalse($definitions['movement_count']->distinct);
    }

    public function test_lookup_method_is_reflected_in_definition(): void
    {
        $definitions = $this->customer->getFlowFieldDefinitions();

        $this->assertEquals('lookup', $definitions['latest_entry_type']->method);
        $this->assertEquals('latestEntry', $definitions['latest_entry_type']->relation);
        $this->assertEquals('type', $definitions['latest_entry_type']->column);
    }

    public function test_no_cache_ttl_is_reflected_in_definition(): void
    {
        $definitions = $this->customer->getFlowFieldDefinitions();

        $this->assertEquals(0, $definitions['live_balance']->ttl);
    }
}
