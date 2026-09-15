<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;

beforeEach(function () {
    $this->customer = TestCustomer::create(['name' => 'OfMany Corp']);
});

// --- ofMany: 'latest' ---

it('latest-of-many returns column from newest entry', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'credit',
    ]));

    expect($this->customer->latest_entry_type_inline)->toBe('credit');
});

it('latest-of-many updates when new entry added', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    expect($this->customer->latest_entry_type_inline)->toBe('invoice');

    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'credit']);

    expect(TestCustomer::find($this->customer->id)->latest_entry_type_inline)->toBe('credit');
});

// --- ofMany: 'oldest' ---

it('oldest-of-many returns column from first entry', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'credit',
    ]));

    expect($this->customer->oldest_entry_type)->toBe('invoice');
});

it('oldest does not change when new entry added', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    expect($this->customer->oldest_entry_type)->toBe('invoice');

    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 200, 'type' => 'credit']);

    expect(TestCustomer::find($this->customer->id)->oldest_entry_type)->toBe('invoice');
});

// --- ofMany: 'max' ---

it('max-of-many returns value of highest-amount entry', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 300, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 9999, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'credit',
    ]));

    expect((float) $this->customer->largest_entry_amount)->toBe(9999.0);
});

it('max-of-many updates when larger entry created', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 500, 'type' => 'invoice',
    ]));

    expect((float) $this->customer->largest_entry_amount)->toBe(500.0);

    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 10000, 'type' => 'invoice']);

    expect((float) TestCustomer::find($this->customer->id)->largest_entry_amount)->toBe(10000.0);
});

// --- ofMany: 'min' ---

it('min-of-many returns value of lowest-amount entry', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 300, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => -50, 'type' => 'credit',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    expect((float) $this->customer->smallest_entry_amount)->toBe(-50.0);
});

// --- ofMany: ['col', 'agg'] custom pair ---

it('custom column pair picks by one column and returns another', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 9000, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 50, 'type' => 'credit',
    ]));

    expect($this->customer->type_of_largest_entry)->toBe('invoice');
});

it('custom column pair with credit as largest', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 99999, 'type' => 'credit',
    ]));

    expect($this->customer->type_of_largest_entry)->toBe('credit');
});

// --- Null when no records ---

it('all ofMany variants return null with no entries', function () {
    expect($this->customer->latest_entry_type_inline)->toBeNull();
    expect($this->customer->oldest_entry_type)->toBeNull();
    expect($this->customer->largest_entry_amount)->toBeNull();
    expect($this->customer->smallest_entry_amount)->toBeNull();
    expect($this->customer->type_of_largest_entry)->toBeNull();
});

// --- Caching behaviour ---

it('ofMany value is cached after first access', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $this->customer->largest_entry_amount;

    $queryCount = 0;
    DB::listen(fn () => $queryCount++);

    expect((float) $this->customer->largest_entry_amount)->toBe(100.0);
    expect($queryCount)->toBe(0);
});

it('ofMany cache is invalidated on create', function () {
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $this->customer->largest_entry_amount;
    $key = "flowfield:test_customers:{$this->customer->id}:largest_entry_amount";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 5000, 'type' => 'invoice']);

    expect(Cache::store('array')->get($key))->toBeNull();
    expect((float) TestCustomer::find($this->customer->id)->largest_entry_amount)->toBe(5000.0);
});

it('ofMany cache is invalidated on update', function () {
    $entry = TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $this->customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $this->customer->largest_entry_amount;
    $key = "flowfield:test_customers:{$this->customer->id}:largest_entry_amount";
    expect(Cache::store('array')->get($key))->not->toBeNull();

    $entry->update(['amount' => 9999]);

    expect(Cache::store('array')->get($key))->toBeNull();
    expect((float) TestCustomer::find($this->customer->id)->largest_entry_amount)->toBe(9999.0);
});

// --- Definition metadata ---

it('ofMany variants are reflected in definition', function () {
    $defs = $this->customer->getFlowFieldDefinitions();

    expect($defs['latest_entry_type_inline']->ofMany)->toBe('latest');
    expect($defs['oldest_entry_type']->ofMany)->toBe('oldest');
    expect($defs['largest_entry_amount']->ofMany)->toBe('max');
    expect($defs['smallest_entry_amount']->ofMany)->toBe('min');
    expect($defs['type_of_largest_entry']->ofMany)->toBe(['amount', 'max']);
});

it('null ofMany in definition for regular flowfield', function () {
    $defs = $this->customer->getFlowFieldDefinitions();
    expect($defs['balance']->ofMany)->toBeNull();
});
