<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;

it('warm command warms cache for specific model and id', function () {
    $customer = TestCustomer::create(['name' => 'Test']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $this->artisan('flowfield:warm', [
        'model' => TestCustomer::class,
        '--id' => $customer->id,
    ])->assertSuccessful();

    $key = "flowfield:test_customers:{$customer->id}:balance";
    expect(Cache::store('array')->get($key))->not->toBeNull();
});

it('warm command with --field only warms the specified field', function () {
    $customer = TestCustomer::create(['name' => 'Test']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $this->artisan('flowfield:warm', [
        'model' => TestCustomer::class,
        '--id' => $customer->id,
        '--field' => 'balance',
    ])->assertSuccessful();

    expect(Cache::store('array')->get("flowfield:test_customers:{$customer->id}:balance"))->not->toBeNull();
    expect(Cache::store('array')->get("flowfield:test_customers:{$customer->id}:entry_count"))->toBeNull();
});

it('warm command warms all records of a model', function () {
    $c1 = TestCustomer::create(['name' => 'Customer 1']);
    $c2 = TestCustomer::create(['name' => 'Customer 2']);

    TestEntry::withoutEvents(function () use ($c1, $c2) {
        TestEntry::create(['customer_id' => $c1->id, 'amount' => 100, 'type' => 'invoice']);
        TestEntry::create(['customer_id' => $c2->id, 'amount' => 200, 'type' => 'invoice']);
    });

    $this->artisan('flowfield:warm', ['model' => TestCustomer::class])->assertSuccessful();

    expect(Cache::store('array')->get("flowfield:test_customers:{$c1->id}:balance"))->not->toBeNull();
    expect(Cache::store('array')->get("flowfield:test_customers:{$c2->id}:balance"))->not->toBeNull();
});

it('warm command fails for invalid model class', function () {
    $this->artisan('flowfield:warm', ['model' => 'App\\Models\\NonExistent'])->assertFailed();
});

it('warm command fails for non-existent id', function () {
    $this->artisan('flowfield:warm', [
        'model' => TestCustomer::class,
        '--id' => 99999,
    ])->assertFailed();
});
