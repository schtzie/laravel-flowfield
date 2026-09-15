<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Schtzie\FlowField\Tests\Fixtures\TestPurchaseLine;
use Schtzie\FlowField\Tests\Fixtures\TestVendor;

beforeEach(function () {
    $this->supplier = TestVendor::create(['name' => 'Acme Supplies Ltd']);
});

// --- outstanding_amount ---

it('outstanding_amount reflects only open lines', function () {
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 500, 'status' => 'open',
    ]));
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 300, 'status' => 'open',
    ]));
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 200, 'status' => 'paid',
    ]));

    expect((float) $this->supplier->outstanding_amount)->toBe(800.0);
});

it('paid_amount reflects only paid lines', function () {
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 1000, 'status' => 'open',
    ]));
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 400, 'status' => 'paid',
    ]));
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 600, 'status' => 'paid',
    ]));

    expect((float) $this->supplier->paid_amount)->toBe(1000.0);
});

it('paying a line shifts amount from outstanding to paid', function () {
    $line = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 750, 'status' => 'open',
    ]));

    expect((float) $this->supplier->outstanding_amount)->toBe(750.0);
    expect((float) $this->supplier->paid_amount)->toBe(0.0);

    $line->update(['status' => 'paid']);

    $fresh = TestVendor::find($this->supplier->id);
    expect((float) $fresh->outstanding_amount)->toBe(0.0);
    expect((float) $fresh->paid_amount)->toBe(750.0);
});

// --- open_order_count ---

it('open_order_count decrements after payment', function () {
    $line1 = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 100, 'status' => 'open',
    ]));
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 200, 'status' => 'open',
    ]));

    expect($this->supplier->open_order_count)->toBe(2);

    $line1->update(['status' => 'paid']);

    expect(TestVendor::find($this->supplier->id)->open_order_count)->toBe(1);
});

it('open_order_count is zero when all lines paid', function () {
    $line = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 500, 'status' => 'open',
    ]));

    expect($this->supplier->open_order_count)->toBe(1);

    $line->update(['status' => 'paid']);

    expect(TestVendor::find($this->supplier->id)->open_order_count)->toBe(0);
});

// --- has_open_orders ---

it('has_open_orders is true when open lines exist', function () {
    TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 250, 'status' => 'open',
    ]);

    expect(TestVendor::find($this->supplier->id)->has_open_orders)->toBeTrue();
});

it('has_open_orders flips to false when last open line is paid', function () {
    $line = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 250, 'status' => 'open',
    ]));

    expect($this->supplier->has_open_orders)->toBeTrue();

    $line->update(['status' => 'paid']);

    expect(TestVendor::find($this->supplier->id)->has_open_orders)->toBeFalse();
});

it('has_open_orders is false for vendor with no lines', function () {
    expect($this->supplier->has_open_orders)->toBeFalse();
});

// --- largest_order ---

it('largest_order reflects true maximum', function () {
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 100, 'status' => 'open',
    ]));
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 9999, 'status' => 'open',
    ]));
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 50, 'status' => 'paid',
    ]));

    expect((float) $this->supplier->largest_order)->toBe(9999.0);
});

// --- average_order_value ---

it('average_order_value recalculates on new line', function () {
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 100, 'status' => 'open',
    ]));
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 200, 'status' => 'open',
    ]));

    expect((float) $this->supplier->average_order_value)->toEqualWithDelta(150.0, 0.01);

    TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 300, 'status' => 'open',
    ]);

    expect((float) TestVendor::find($this->supplier->id)->average_order_value)->toEqualWithDelta(200.0, 0.01);
});

// --- Multi-vendor isolation ---

it('flowfields are isolated per vendor', function () {
    $other = TestVendor::create(['name' => 'Beta Wholesalers']);

    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 1000, 'status' => 'open',
    ]));
    TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $other->id, 'amount' => 250, 'status' => 'open',
    ]));

    expect((float) $this->supplier->outstanding_amount)->toBe(1000.0);
    expect((float) $other->outstanding_amount)->toBe(250.0);
});

// --- Cross-vendor invalidation ---

it('reassigning purchase line invalidates both vendors caches', function () {
    $other = TestVendor::create(['name' => 'Beta Wholesalers']);

    $line = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 500, 'status' => 'open',
    ]));

    $this->supplier->calcFlowFields('outstanding_amount');
    $other->calcFlowFields('outstanding_amount');

    $key1 = "flowfield:test_vendors:{$this->supplier->id}:outstanding_amount";
    $key2 = "flowfield:test_vendors:{$other->id}:outstanding_amount";

    expect(Cache::store('array')->get($key1))->not->toBeNull();
    expect(Cache::store('array')->get($key2))->not->toBeNull();

    $line->update(['vendor_id' => $other->id]);

    expect(Cache::store('array')->get($key1))->toBeNull();
    expect(Cache::store('array')->get($key2))->toBeNull();

    expect((float) TestVendor::find($this->supplier->id)->outstanding_amount)->toBe(0.0);
    expect((float) TestVendor::find($other->id)->outstanding_amount)->toBe(500.0);
});

it('updating only irrelevant column does not invalidate cache', function () {
    $line = TestPurchaseLine::withoutEvents(fn () => TestPurchaseLine::create([
        'vendor_id' => $this->supplier->id, 'amount' => 300, 'status' => 'open',
    ]));

    $this->supplier->calcFlowFields('outstanding_amount');
    $key = "flowfield:test_vendors:{$this->supplier->id}:outstanding_amount";
    $cachedBefore = Cache::store('array')->get($key);

    $line->updated_at = now()->addHour();
    $line->save();

    expect(Cache::store('array')->get($key))->toBe($cachedBefore);
});
