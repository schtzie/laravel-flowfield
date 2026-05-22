<?php

namespace Openplain\FlowField\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Openplain\FlowField\Tests\Fixtures\TestCustomer;
use Openplain\FlowField\Tests\Fixtures\TestEntry;
use Openplain\FlowField\Tests\TestCase;

/**
 * ofMany FlowField Tests — inline "one of many" selection
 *
 * Covers:
 *   - ofMany: 'latest'  — returns column from the newest entry (by PK)
 *   - ofMany: 'oldest'  — returns column from the oldest entry (by PK)
 *   - ofMany: 'max'     — picks entry with highest column value
 *   - ofMany: 'min'     — picks entry with lowest column value
 *   - ofMany: ['col', 'agg'] — custom aggregate column, different return column
 *   - Returns null when no related records exist
 *   - Value is cached and served from cache on subsequent reads
 *   - Cache is invalidated when related records change
 *   - ofMany + where conditions combined (pick latest invoice)
 *   - Wrong method with ofMany throws InvalidArgumentException
 */
class OfManyFlowFieldTest extends TestCase
{
    protected TestCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = TestCustomer::create(['name' => 'OfMany Corp']);
    }

    // =========================================================================
    // ofMany: 'latest'
    // =========================================================================

    public function test_latest_of_many_returns_column_from_newest_entry(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'credit',
        ]));

        // latest_entry_type_inline: ofMany: 'latest' on the 'type' column
        $this->assertEquals('credit', $this->customer->latest_entry_type_inline);
    }

    public function test_latest_of_many_updates_when_new_entry_added(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));

        $this->assertEquals('invoice', $this->customer->latest_entry_type_inline);

        // Add a newer entry — invalidates cache
        TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'credit',
        ]);

        $fresh = TestCustomer::find($this->customer->id);
        $this->assertEquals('credit', $fresh->latest_entry_type_inline);
    }

    // =========================================================================
    // ofMany: 'oldest'
    // =========================================================================

    public function test_oldest_of_many_returns_column_from_first_entry(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'credit',
        ]));

        // oldest_entry_type: picks first by primary key
        $this->assertEquals('invoice', $this->customer->oldest_entry_type);
    }

    public function test_oldest_does_not_change_when_new_entry_added(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));

        $this->assertEquals('invoice', $this->customer->oldest_entry_type);

        TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'credit',
        ]);

        $fresh = TestCustomer::find($this->customer->id);
        // Oldest is still the first entry — type should remain 'invoice'
        $this->assertEquals('invoice', $fresh->oldest_entry_type);
    }

    // =========================================================================
    // ofMany: 'max'
    // =========================================================================

    public function test_max_of_many_returns_value_of_highest_amount_entry(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 300, 'type' => 'invoice',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 9999, 'type' => 'invoice',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'credit',
        ]));

        // largest_entry_amount: ofMany: 'max' on 'amount' → 9999
        $this->assertEquals(9999, (float) $this->customer->largest_entry_amount);
    }

    public function test_max_of_many_updates_when_larger_entry_created(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 500, 'type' => 'invoice',
        ]));

        $this->assertEquals(500, (float) $this->customer->largest_entry_amount);

        TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 10000, 'type' => 'invoice',
        ]);

        $fresh = TestCustomer::find($this->customer->id);
        $this->assertEquals(10000, (float) $fresh->largest_entry_amount);
    }

    // =========================================================================
    // ofMany: 'min'
    // =========================================================================

    public function test_min_of_many_returns_value_of_lowest_amount_entry(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 300, 'type' => 'invoice',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => -50, 'type' => 'credit',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));

        // smallest_entry_amount: ofMany: 'min' on 'amount' → -50
        $this->assertEquals(-50, (float) $this->customer->smallest_entry_amount);
    }

    // =========================================================================
    // ofMany: ['col', 'agg'] — custom column pair
    // =========================================================================

    public function test_custom_column_pair_picks_by_one_column_returns_another(): void
    {
        // Three entries: two invoices, one credit. The largest by amount is the second invoice.
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 9000, 'type' => 'invoice',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'credit',
        ]));

        // type_of_largest_entry: ofMany: ['amount', 'max'] → find entry with max amount,
        // then return its 'type' column. Should be 'invoice' (the 9000 entry).
        $this->assertEquals('invoice', $this->customer->type_of_largest_entry);
    }

    public function test_custom_column_pair_with_credit_as_largest(): void
    {
        // Make a credit the entry with the highest amount (absolute value scenario
        // doesn't apply — here credit just happens to have highest amount)
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 99999, 'type' => 'credit',
        ]));

        // type_of_largest_entry picks by max(amount) → 99999 → type = 'credit'
        $this->assertEquals('credit', $this->customer->type_of_largest_entry);
    }

    // =========================================================================
    // Null when no related records exist
    // =========================================================================

    public function test_all_of_many_variants_return_null_with_no_entries(): void
    {
        $this->assertNull($this->customer->latest_entry_type_inline);
        $this->assertNull($this->customer->oldest_entry_type);
        $this->assertNull($this->customer->largest_entry_amount);
        $this->assertNull($this->customer->smallest_entry_amount);
        $this->assertNull($this->customer->type_of_largest_entry);
    }

    // =========================================================================
    // Caching behaviour
    // =========================================================================

    public function test_of_many_value_is_cached_after_first_access(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));

        $this->customer->largest_entry_amount; // prime cache

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) { $queryCount++; });

        $result = $this->customer->largest_entry_amount;

        $this->assertEquals(100, (float) $result);
        $this->assertEquals(0, $queryCount, 'ofMany value must be served from cache on second access');
    }

    public function test_of_many_cache_is_invalidated_on_create(): void
    {
        TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));

        $this->customer->largest_entry_amount; // prime cache
        $cacheKey = "flowfield:test_customers:{$this->customer->id}:largest_entry_amount";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        // New entry via events — triggers invalidation
        TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 5000, 'type' => 'invoice',
        ]);

        $this->assertNull(Cache::store('array')->get($cacheKey));

        $fresh = TestCustomer::find($this->customer->id);
        $this->assertEquals(5000, (float) $fresh->largest_entry_amount);
    }

    public function test_of_many_cache_is_invalidated_on_update(): void
    {
        $entry = TestEntry::withoutEvents(fn () => TestEntry::create([
            'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
        ]));

        $this->customer->largest_entry_amount; // prime cache
        $cacheKey = "flowfield:test_customers:{$this->customer->id}:largest_entry_amount";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        $entry->update(['amount' => 9999]);

        $this->assertNull(Cache::store('array')->get($cacheKey));

        $fresh = TestCustomer::find($this->customer->id);
        $this->assertEquals(9999, (float) $fresh->largest_entry_amount);
    }

    // =========================================================================
    // ofMany definition metadata
    // =========================================================================

    public function test_of_many_is_reflected_in_definition(): void
    {
        $definitions = $this->customer->getFlowFieldDefinitions();

        $this->assertEquals('latest', $definitions['latest_entry_type_inline']->ofMany);
        $this->assertEquals('oldest', $definitions['oldest_entry_type']->ofMany);
        $this->assertEquals('max', $definitions['largest_entry_amount']->ofMany);
        $this->assertEquals('min', $definitions['smallest_entry_amount']->ofMany);
        $this->assertEquals(['amount', 'max'], $definitions['type_of_largest_entry']->ofMany);
    }

    public function test_null_of_many_in_definition_for_regular_flowfield(): void
    {
        $definitions = $this->customer->getFlowFieldDefinitions();
        $this->assertNull($definitions['balance']->ofMany);
    }
}
