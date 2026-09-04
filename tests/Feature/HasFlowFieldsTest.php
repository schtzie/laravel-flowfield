<?php

namespace Schtzie\FlowField\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;
use Schtzie\FlowField\Tests\TestCase;

class HasFlowFieldsTest extends TestCase
{
    protected TestCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = TestCustomer::create(['name' => 'Acme Corp']);

        TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice']);
        TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'invoice']);
        TestEntry::create(['customer_id' => $this->customer->id, 'amount' => -50, 'type' => 'credit']);
    }

    public function test_accessing_flow_field_calculates_on_cache_miss(): void
    {
        $balance = $this->customer->balance;

        $this->assertEquals(250, (float) $balance);
    }

    public function test_second_access_hits_cache(): void
    {
        // First access — triggers calculation
        $this->customer->balance;

        // Count queries on second access
        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $balance = $this->customer->balance;

        $this->assertEquals(250, (float) $balance);
        $this->assertEquals(0, $queryCount);
    }

    public function test_sum_with_where_conditions(): void
    {
        $this->assertEquals(300, (float) $this->customer->total_invoiced);
    }

    public function test_count_flow_field(): void
    {
        $this->assertEquals(3, $this->customer->entry_count);
    }

    public function test_exists_flow_field_true(): void
    {
        $this->assertTrue($this->customer->has_entries);
    }

    public function test_exists_flow_field_false(): void
    {
        $emptyCustomer = TestCustomer::create(['name' => 'Empty']);

        $this->assertFalse($emptyCustomer->has_entries);
    }

    public function test_avg_flow_field(): void
    {
        $this->assertEqualsWithDelta(83.33, (float) $this->customer->average_amount, 0.01);
    }

    public function test_min_flow_field(): void
    {
        $this->assertEquals(-50, (float) $this->customer->min_amount);
    }

    public function test_max_flow_field(): void
    {
        $this->assertEquals(200, (float) $this->customer->max_amount);
    }

    public function test_calc_flow_fields_forces_recalculation(): void
    {
        // Prime the cache with wrong value
        Cache::store('array')->put("flowfield:test_customers:{$this->customer->id}:balance", 999);

        $this->customer->calcFlowFields('balance');

        $this->assertEquals(250, (float) $this->customer->balance);
    }

    public function test_flush_flow_fields_clears_cache(): void
    {
        // Prime cache
        $this->customer->balance;

        $this->customer->flushFlowFields('balance');

        $cacheKey = "flowfield:test_customers:{$this->customer->id}:balance";
        $this->assertNull(Cache::store('array')->get($cacheKey));
    }

    public function test_flush_all_flow_fields(): void
    {
        // Prime all caches
        $this->customer->balance;
        $this->customer->entry_count;

        $this->customer->flushFlowFields();

        $this->assertNull(Cache::store('array')->get("flowfield:test_customers:{$this->customer->id}:balance"));
        $this->assertNull(Cache::store('array')->get("flowfield:test_customers:{$this->customer->id}:entry_count"));
    }

    public function test_get_flow_field_definitions(): void
    {
        $definitions = $this->customer->getFlowFieldDefinitions();

        $this->assertArrayHasKey('balance', $definitions);
        $this->assertArrayHasKey('total_invoiced', $definitions);
        $this->assertArrayHasKey('entry_count', $definitions);
        $this->assertArrayHasKey('has_entries', $definitions);

        $this->assertEquals('sum', $definitions['balance']->method);
        $this->assertEquals('entries', $definitions['balance']->relation);
        $this->assertEquals('amount', $definitions['balance']->column);
    }

    public function test_with_flow_fields_scope(): void
    {
        $customers = TestCustomer::withFlowFields('balance', 'entry_count')->get();

        // After scope, values should be cached
        $cacheKey = "flowfield:test_customers:{$this->customer->id}:balance";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));
    }

    public function test_order_by_flow_field(): void
    {
        $customer2 = TestCustomer::create(['name' => 'Big Corp']);
        TestEntry::create(['customer_id' => $customer2->id, 'amount' => 1000, 'type' => 'invoice']);

        // Clear any cached static registry since we're creating new entries
        $customer2->flushFlowFields();

        $ordered = TestCustomer::orderByFlowField('balance', 'desc')->pluck('name')->toArray();

        $this->assertEquals('Big Corp', $ordered[0]);
        $this->assertEquals('Acme Corp', $ordered[1]);
    }

    public function test_flow_field_works_without_cache(): void
    {
        // Flush cache then access — should recalculate transparently
        Cache::store('array')->flush();

        $balance = $this->customer->balance;

        $this->assertEquals(250, (float) $balance);
    }
}
