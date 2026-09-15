<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;

beforeEach(function () {
    $this->customer = TestCustomer::create(['name' => 'Acme Corp']);

    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice']);
    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'invoice']);
    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => -50, 'type' => 'credit']);
});

it('calculates balance on cache miss', function () {
    expect((float) $this->customer->balance)->toBe(250.0);
});

it('serves balance from cache on second access', function () {
    $this->customer->balance; // prime cache

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    $balance = $this->customer->balance;

    expect((float) $balance)->toBe(250.0);
    expect($queryCount)->toBe(0);
});

it('applies where conditions on sum', function () {
    expect((float) $this->customer->total_invoiced)->toBe(300.0);
});

it('counts all entries', function () {
    expect($this->customer->entry_count)->toBe(3);
});

it('returns true for exists when entries present', function () {
    expect($this->customer->has_entries)->toBeTrue();
});

it('returns false for exists when no entries', function () {
    $emptyCustomer = TestCustomer::create(['name' => 'Empty']);
    expect($emptyCustomer->has_entries)->toBeFalse();
});

it('calculates average amount', function () {
    expect((float) $this->customer->average_amount)->toEqualWithDelta(83.33, 0.01);
});

it('finds minimum amount', function () {
    expect((float) $this->customer->min_amount)->toBe(-50.0);
});

it('finds maximum amount', function () {
    expect((float) $this->customer->max_amount)->toBe(200.0);
});

it('calcFlowFields forces recalculation over stale cache', function () {
    Cache::store('array')->put("flowfield:test_customers:{$this->customer->id}:balance", 999);

    $this->customer->calcFlowFields('balance');

    expect((float) $this->customer->balance)->toBe(250.0);
});

it('flushFlowFields clears specific cache entry', function () {
    $this->customer->balance; // prime cache
    $this->customer->flushFlowFields('balance');

    $key = "flowfield:test_customers:{$this->customer->id}:balance";
    expect(Cache::store('array')->get($key))->toBeNull();
});

it('flushFlowFields with no args clears all fields', function () {
    $this->customer->balance;
    $this->customer->entry_count;
    $this->customer->flushFlowFields();

    expect(Cache::store('array')->get("flowfield:test_customers:{$this->customer->id}:balance"))->toBeNull();
    expect(Cache::store('array')->get("flowfield:test_customers:{$this->customer->id}:entry_count"))->toBeNull();
});

it('getFlowFieldDefinitions returns correct metadata', function () {
    $defs = $this->customer->getFlowFieldDefinitions();

    expect($defs)->toHaveKeys(['balance', 'total_invoiced', 'entry_count', 'has_entries']);
    expect($defs['balance']->method)->toBe('sum');
    expect($defs['balance']->relation)->toBe('entries');
    expect($defs['balance']->column)->toBe('amount');
});

it('withFlowFields scope pre-warms cache for collection', function () {
    TestCustomer::withFlowFields('balance', 'entry_count')->get();

    $key = "flowfield:test_customers:{$this->customer->id}:balance";
    expect(Cache::store('array')->get($key))->not->toBeNull();
});

it('orderByFlowField sorts customers by balance descending', function () {
    $customer2 = TestCustomer::create(['name' => 'Big Corp']);
    TestEntry::create(['customer_id' => $customer2->id, 'amount' => 1000, 'type' => 'invoice']);
    $customer2->flushFlowFields();

    $ordered = TestCustomer::orderByFlowField('balance', 'desc')->pluck('name')->toArray();

    expect($ordered[0])->toBe('Big Corp');
    expect($ordered[1])->toBe('Acme Corp');
});

it('recalculates correctly after cache flush', function () {
    Cache::store('array')->flush();

    expect((float) $this->customer->balance)->toBe(250.0);
});
