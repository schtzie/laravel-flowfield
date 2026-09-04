<?php

namespace Schtzie\FlowField\Tests\Unit;

use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\TestCase;

class FlowFieldCacheTest extends TestCase
{
    public function test_cache_key_format(): void
    {
        $customer = TestCustomer::create(['name' => 'Test']);

        $key = FlowFieldCache::buildKey($customer, 'balance');

        $this->assertEquals("flowfield:test_customers:{$customer->id}:balance", $key);
    }

    public function test_put_and_get(): void
    {
        $customer = TestCustomer::create(['name' => 'Test']);

        FlowFieldCache::put($customer, 'balance', 100.50);

        $this->assertEquals(100.50, FlowFieldCache::get($customer, 'balance'));
    }

    public function test_get_returns_cached_value(): void
    {
        $customer = TestCustomer::create(['name' => 'Test']);

        FlowFieldCache::put($customer, 'balance', 42);

        $this->assertEquals(42, FlowFieldCache::get($customer, 'balance'));
    }

    public function test_get_returns_null_for_missing_values(): void
    {
        $customer = TestCustomer::create(['name' => 'Test']);

        $this->assertNull(FlowFieldCache::get($customer, 'balance'));
    }

    public function test_invalidate_removes_specific_field(): void
    {
        $customer = TestCustomer::create(['name' => 'Test']);

        FlowFieldCache::put($customer, 'balance', 100);
        FlowFieldCache::put($customer, 'entry_count', 5);

        FlowFieldCache::invalidate(TestCustomer::class, $customer->id, 'balance');

        $this->assertNull(FlowFieldCache::get($customer, 'balance'));
        $this->assertEquals(5, FlowFieldCache::get($customer, 'entry_count'));
    }

    public function test_invalidate_all_removes_all_fields(): void
    {
        $customer = TestCustomer::create(['name' => 'Test']);

        FlowFieldCache::put($customer, 'balance', 100);
        FlowFieldCache::put($customer, 'entry_count', 5);

        FlowFieldCache::invalidateAll(TestCustomer::class, $customer->id);

        $this->assertNull(FlowFieldCache::get($customer, 'balance'));
        $this->assertNull(FlowFieldCache::get($customer, 'entry_count'));
    }

    public function test_cache_preserves_decimal_precision(): void
    {
        $customer = TestCustomer::create(['name' => 'Test']);

        FlowFieldCache::put($customer, 'balance', 1234.56789);

        $this->assertEquals(1234.56789, FlowFieldCache::get($customer, 'balance'));
    }

    public function test_cache_handles_boolean_values(): void
    {
        $customer = TestCustomer::create(['name' => 'Test']);

        FlowFieldCache::put($customer, 'has_entries', true);
        $this->assertTrue(FlowFieldCache::get($customer, 'has_entries'));

        FlowFieldCache::put($customer, 'has_entries', false);
        $this->assertFalse(FlowFieldCache::get($customer, 'has_entries'));
    }
}
