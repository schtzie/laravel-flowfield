<?php

namespace Schtzie\FlowField\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;
use Schtzie\FlowField\Tests\TestCase;

class FlushCommandTest extends TestCase
{
    public function test_flush_command_for_specific_model_and_id(): void
    {
        $customer = TestCustomer::create(['name' => 'Test']);
        TestEntry::withoutEvents(function () use ($customer) {
            TestEntry::create(['customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice']);
        });

        $customer->calcFlowFields();

        $cacheKey = "flowfield:test_customers:{$customer->id}:balance";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));

        $this->artisan('flowfield:flush', [
            'model' => TestCustomer::class,
            '--id' => $customer->id,
        ])->assertSuccessful();

        $this->assertNull(Cache::store('array')->get($cacheKey));
    }

    public function test_flush_command_for_all_records_of_model(): void
    {
        $customer1 = TestCustomer::create(['name' => 'Customer 1']);
        $customer2 = TestCustomer::create(['name' => 'Customer 2']);

        TestEntry::withoutEvents(function () use ($customer1, $customer2) {
            TestEntry::create(['customer_id' => $customer1->id, 'amount' => 100, 'type' => 'invoice']);
            TestEntry::create(['customer_id' => $customer2->id, 'amount' => 200, 'type' => 'invoice']);
        });

        $customer1->calcFlowFields();
        $customer2->calcFlowFields();

        $this->artisan('flowfield:flush', [
            'model' => TestCustomer::class,
        ])->assertSuccessful();

        $this->assertNull(Cache::store('array')->get("flowfield:test_customers:{$customer1->id}:balance"));
        $this->assertNull(Cache::store('array')->get("flowfield:test_customers:{$customer2->id}:balance"));
    }

    public function test_flush_command_fails_for_invalid_model(): void
    {
        $this->artisan('flowfield:flush', [
            'model' => 'App\\Models\\NonExistent',
        ])->assertFailed();
    }

    public function test_flush_command_requires_model_when_using_id(): void
    {
        $this->artisan('flowfield:flush', [
            '--id' => 1,
        ])->assertFailed();
    }
}
