<?php

namespace Schtzie\FlowField\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;
use Schtzie\FlowField\Tests\TestCase;

class WarmCommandTest extends TestCase
{
    public function test_warm_command_for_specific_model_and_id(): void
    {
        $customer = TestCustomer::create(['name' => 'Test']);
        TestEntry::withoutEvents(function () use ($customer) {
            TestEntry::create(['customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice']);
        });

        $this->artisan('flowfield:warm', [
            'model' => TestCustomer::class,
            '--id' => $customer->id,
        ])->assertSuccessful();

        $cacheKey = "flowfield:test_customers:{$customer->id}:balance";
        $this->assertNotNull(Cache::store('array')->get($cacheKey));
    }

    public function test_warm_command_for_specific_field(): void
    {
        $customer = TestCustomer::create(['name' => 'Test']);
        TestEntry::withoutEvents(function () use ($customer) {
            TestEntry::create(['customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice']);
        });

        $this->artisan('flowfield:warm', [
            'model' => TestCustomer::class,
            '--id' => $customer->id,
            '--field' => 'balance',
        ])->assertSuccessful();

        $balanceKey = "flowfield:test_customers:{$customer->id}:balance";
        $countKey = "flowfield:test_customers:{$customer->id}:entry_count";

        $this->assertNotNull(Cache::store('array')->get($balanceKey));
        $this->assertNull(Cache::store('array')->get($countKey));
    }

    public function test_warm_command_for_all_records_of_model(): void
    {
        $customer1 = TestCustomer::create(['name' => 'Customer 1']);
        $customer2 = TestCustomer::create(['name' => 'Customer 2']);

        TestEntry::withoutEvents(function () use ($customer1, $customer2) {
            TestEntry::create(['customer_id' => $customer1->id, 'amount' => 100, 'type' => 'invoice']);
            TestEntry::create(['customer_id' => $customer2->id, 'amount' => 200, 'type' => 'invoice']);
        });

        $this->artisan('flowfield:warm', [
            'model' => TestCustomer::class,
        ])->assertSuccessful();

        $this->assertNotNull(Cache::store('array')->get("flowfield:test_customers:{$customer1->id}:balance"));
        $this->assertNotNull(Cache::store('array')->get("flowfield:test_customers:{$customer2->id}:balance"));
    }

    public function test_warm_command_fails_for_invalid_model(): void
    {
        $this->artisan('flowfield:warm', [
            'model' => 'App\\Models\\NonExistent',
        ])->assertFailed();
    }

    public function test_warm_command_fails_for_invalid_id(): void
    {
        $this->artisan('flowfield:warm', [
            'model' => TestCustomer::class,
            '--id' => 99999,
        ])->assertFailed();
    }
}
