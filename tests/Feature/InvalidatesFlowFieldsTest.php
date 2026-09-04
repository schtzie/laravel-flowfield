<?php

namespace Schtzie\FlowField\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;
use Schtzie\FlowField\Tests\TestCase;

class InvalidatesFlowFieldsTest extends TestCase
{
    protected TestCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = TestCustomer::create(['name' => 'Acme Corp']);

        // Prime balance cache
        TestEntry::withoutEvents(function () {
            TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice']);
        });

        // Force cache the current balance
        $this->customer->calcFlowFields('balance');
    }

    public function test_creating_entry_invalidates_cache(): void
    {
        $cacheKey = "flowfield:test_customers:{$this->customer->id}:balance";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice']);

        $this->assertNull(Cache::store('array')->get($cacheKey));
    }

    public function test_updating_entry_invalidates_cache(): void
    {
        $entry = TestEntry::withoutEvents(function () {
            return TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice']);
        });

        $this->customer->calcFlowFields('balance');
        $cacheKey = "flowfield:test_customers:{$this->customer->id}:balance";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        $entry->update(['amount' => 75]);

        $this->assertNull(Cache::store('array')->get($cacheKey));
    }

    public function test_deleting_entry_invalidates_cache(): void
    {
        $entry = TestEntry::withoutEvents(function () {
            return TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice']);
        });

        $this->customer->calcFlowFields('balance');
        $cacheKey = "flowfield:test_customers:{$this->customer->id}:balance";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        $entry->forceDelete();

        $this->assertNull(Cache::store('array')->get($cacheKey));
    }

    public function test_updating_irrelevant_column_skips_invalidation(): void
    {
        $entry = TestEntry::withoutEvents(function () {
            return TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice']);
        });

        $this->customer->calcFlowFields('balance');
        $cacheKey = "flowfield:test_customers:{$this->customer->id}:balance";
        $cachedValue = Cache::store('array')->get($cacheKey);

        // Update the timestamps only (no relevant columns)
        $entry->updated_at = now()->addHour();
        $entry->save();

        // Cache should still be there since no relevant columns changed
        $this->assertEquals($cachedValue, Cache::store('array')->get($cacheKey));
    }

    public function test_changing_foreign_key_invalidates_both_parents(): void
    {
        $customer2 = TestCustomer::create(['name' => 'Other Corp']);

        $entry = TestEntry::withoutEvents(function () {
            return TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice']);
        });

        // Prime both customers' caches
        $this->customer->calcFlowFields('balance');
        $customer2->calcFlowFields('balance');

        $cacheKey1 = "flowfield:test_customers:{$this->customer->id}:balance";
        $cacheKey2 = "flowfield:test_customers:{$customer2->id}:balance";

        $this->assertNotNull(Cache::store('array')->get($cacheKey1));
        $this->assertNotNull(Cache::store('array')->get($cacheKey2));

        // Move entry from customer 1 to customer 2
        $entry->update(['customer_id' => $customer2->id]);

        // Both parents should be invalidated
        $this->assertNull(Cache::store('array')->get($cacheKey1));
        $this->assertNull(Cache::store('array')->get($cacheKey2));
    }

    public function test_auto_warm_recalculates_after_invalidation(): void
    {
        config(['flowfield.auto_warm' => true]);

        TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'invoice']);

        $cacheKey = "flowfield:test_customers:{$this->customer->id}:balance";
        // With auto_warm, the cache should be re-populated
        $this->assertNotNull(Cache::store('array')->get($cacheKey));
    }

    public function test_restoring_soft_deleted_entry_invalidates_cache(): void
    {
        $entry = TestEntry::withoutEvents(function () {
            return TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'invoice']);
        });

        $entry->delete(); // Soft delete
        $this->customer->calcFlowFields('balance');

        $cacheKey = "flowfield:test_customers:{$this->customer->id}:balance";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        $entry->restore();

        $this->assertNull(Cache::store('array')->get($cacheKey));
    }
}
