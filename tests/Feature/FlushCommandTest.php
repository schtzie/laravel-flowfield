<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;

it('flush command clears cache for specific model and id', function () {
    $customer = TestCustomer::create(['name' => 'Test']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $customer->calcFlowFields();

    $key = "flowfield:test_customers:{$customer->id}:balance";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    $this->artisan('flowfield:flush', [
        'model' => TestCustomer::class,
        '--id' => $customer->id,
    ])->assertSuccessful();

    expect(Cache::store('array')->get($key))->toBeNull();
});

it('flush command clears all records of a model', function () {
    $c1 = TestCustomer::create(['name' => 'Customer 1']);
    $c2 = TestCustomer::create(['name' => 'Customer 2']);

    TestEntry::withoutEvents(function () use ($c1, $c2) {
        TestEntry::create(['customer_id' => $c1->id, 'amount' => 100, 'type' => 'invoice']);
        TestEntry::create(['customer_id' => $c2->id, 'amount' => 200, 'type' => 'invoice']);
    });

    $c1->calcFlowFields();
    $c2->calcFlowFields();

    $this->artisan('flowfield:flush', ['model' => TestCustomer::class])->assertSuccessful();

    expect(Cache::store('array')->get("flowfield:test_customers:{$c1->id}:balance"))->toBeNull();
    expect(Cache::store('array')->get("flowfield:test_customers:{$c2->id}:balance"))->toBeNull();
});

it('flush command fails for invalid model class', function () {
    $this->artisan('flowfield:flush', ['model' => 'App\\Models\\NonExistent'])->assertFailed();
});

it('flush command fails when id provided without model', function () {
    $this->artisan('flowfield:flush', ['--id' => 1])->assertFailed();
});
