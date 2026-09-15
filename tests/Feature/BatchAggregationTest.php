<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;

// ---------------------------------------------------------------------------
// withFlowFieldsBatch scope
// ---------------------------------------------------------------------------

it('withFlowFieldsBatch warms cache in N queries not N×M', function () {
    $c1 = TestCustomer::create(['name' => 'Alpha']);
    $c2 = TestCustomer::create(['name' => 'Beta']);
    $c3 = TestCustomer::create(['name' => 'Gamma']);

    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c1->id, 'amount' => 100, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c2->id, 'amount' => 200, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c3->id, 'amount' => 300, 'type' => 'invoice']));

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    TestCustomer::withFlowFieldsBatch('balance', 'entry_count')->get();

    // 1 SELECT for the models + 1 GROUP BY per field (2 fields) = 3 total
    // Much better than 3 models × 2 fields = 6 queries
    expect($queryCount)->toBeLessThanOrEqual(4);

    // All values are in cache
    expect(FlowFieldCache::get($c1, 'balance'))->not->toBeNull();
    expect(FlowFieldCache::get($c2, 'balance'))->not->toBeNull();
    expect(FlowFieldCache::get($c3, 'balance'))->not->toBeNull();
});

it('withFlowFieldsBatch writes correct values to cache', function () {
    $c1 = TestCustomer::create(['name' => 'Alpha']);
    $c2 = TestCustomer::create(['name' => 'Beta']);

    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c1->id, 'amount' => 150, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c2->id, 'amount' => 450, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c2->id, 'amount' => 50, 'type' => 'invoice']));

    TestCustomer::withFlowFieldsBatch('balance', 'entry_count')->get();

    expect((float) FlowFieldCache::get($c1, 'balance'))->toBe(150.0);
    expect((float) FlowFieldCache::get($c2, 'balance'))->toBe(500.0);
    expect(FlowFieldCache::get($c1, 'entry_count'))->toBe(1);
    expect(FlowFieldCache::get($c2, 'entry_count'))->toBe(2);
});

it('withFlowFieldsBatch subsequent reads are zero-query', function () {
    $c1 = TestCustomer::create(['name' => 'Alpha']);
    $c2 = TestCustomer::create(['name' => 'Beta']);

    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c1->id, 'amount' => 100, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c2->id, 'amount' => 200, 'type' => 'invoice']));

    $customers = TestCustomer::withFlowFieldsBatch('balance')->get();

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    foreach ($customers as $c) {
        $c->balance; // Must hit cache
    }

    expect($queryCount)->toBe(0);
});

it('withFlowFieldsBatch with no specific fields warms cacheable definitions', function () {
    $customer = TestCustomer::create(['name' => 'All Fields Corp']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 500, 'type' => 'invoice',
    ]));

    TestCustomer::withFlowFieldsBatch()->get();

    $defs = $customer->getFlowFieldDefinitions();

    foreach ($defs as $field => $def) {
        // Skip no-cache fields (ttl: 0) — they intentionally never write to cache
        if ($def->ttl === 0) {
            continue;
        }
        expect(FlowFieldCache::get($customer, $field))->not->toBeNull("Expected {$field} to be cached");
    }
});

it('withFlowFieldsBatch on empty collection completes without error', function () {
    // No customers exist — query returns empty collection
    expect(fn () => TestCustomer::withFlowFieldsBatch('balance')->get())->not->toThrow(Throwable::class);
});

it('withFlowFieldsBatch assigns zero to missing parent IDs', function () {
    $c1 = TestCustomer::create(['name' => 'With Entries']);
    $c2 = TestCustomer::create(['name' => 'No Entries']);

    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c1->id, 'amount' => 100, 'type' => 'invoice']));

    TestCustomer::withFlowFieldsBatch('balance')->get();

    expect((float) FlowFieldCache::get($c1, 'balance'))->toBe(100.0);
    expect((float) FlowFieldCache::get($c2, 'balance'))->toBe(0.0);
});

// ---------------------------------------------------------------------------
// batchCalcFlowFields static method
// ---------------------------------------------------------------------------

it('batchCalcFlowFields warms a manually assembled model array', function () {
    $c1 = TestCustomer::create(['name' => 'Batch 1']);
    $c2 = TestCustomer::create(['name' => 'Batch 2']);

    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c1->id, 'amount' => 111, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c2->id, 'amount' => 222, 'type' => 'invoice']));

    TestCustomer::batchCalcFlowFields([$c1, $c2], 'balance');

    expect((float) FlowFieldCache::get($c1, 'balance'))->toBe(111.0);
    expect((float) FlowFieldCache::get($c2, 'balance'))->toBe(222.0);
});

it('batchCalcFlowFields is chunked for large collections', function () {
    $customers = [];

    for ($i = 1; $i <= 5; $i++) {
        $c = TestCustomer::create(['name' => "Corp {$i}"]);
        $cId = $c->id;
        $amt = $i * 100;
        TestEntry::withoutEvents(function () use ($cId, $amt) {
            TestEntry::create(['customer_id' => $cId, 'amount' => $amt, 'type' => 'invoice']);
        });
        $customers[] = $c;
    }

    // Set chunk size to 2 to exercise chunking logic
    config(['flowfield.batch.chunk_size' => 2]);

    TestCustomer::batchCalcFlowFields($customers, 'balance');

    foreach ($customers as $i => $c) {
        expect((float) FlowFieldCache::get($c, 'balance'))->toBe((float) (($i + 1) * 100));
    }
});

// ---------------------------------------------------------------------------
// withFlowFieldSubqueries scope
// ---------------------------------------------------------------------------

it('withFlowFieldSubqueries adds correlated subquery columns', function () {
    $c1 = TestCustomer::create(['name' => 'Subq 1']);
    $c2 = TestCustomer::create(['name' => 'Subq 2']);

    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c1->id, 'amount' => 75, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c2->id, 'amount' => 800, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c2->id, 'amount' => 200, 'type' => 'invoice']));

    // Build a real query through the scope — values should be written to cache
    TestCustomer::withFlowFieldSubqueries('balance')->get();

    expect((float) FlowFieldCache::get($c1, 'balance'))->toBe(75.0);
    expect((float) FlowFieldCache::get($c2, 'balance'))->toBe(1000.0);
});

// ---------------------------------------------------------------------------
// N+1 Prevention for Accounting Features (Phase 6)
// ---------------------------------------------------------------------------

it('withFlowFieldsBatch supports wavg calculation without N+1', function () {
    $item1 = Schtzie\FlowField\Tests\Fixtures\TestItem::create(['sku' => 'ITM-001', 'name' => 'Widget 1']);
    $item2 = Schtzie\FlowField\Tests\Fixtures\TestItem::create(['sku' => 'ITM-002', 'name' => 'Widget 2']);

    // Item 1: WAVG = (10*5 + 20*8) / 30 = 7.0
    Schtzie\FlowField\Tests\Fixtures\TestStockMovement::withoutEvents(function () use ($item1, $item2) {
        Schtzie\FlowField\Tests\Fixtures\TestStockMovement::create(['item_id' => $item1->id, 'movement_type' => 'purchase', 'quantity' => 10, 'unit_cost' => 5.0]);
        Schtzie\FlowField\Tests\Fixtures\TestStockMovement::create(['item_id' => $item1->id, 'movement_type' => 'purchase', 'quantity' => 20, 'unit_cost' => 8.0]);

        // Item 2: WAVG = (50*2 + 50*4) / 100 = 3.0
        Schtzie\FlowField\Tests\Fixtures\TestStockMovement::create(['item_id' => $item2->id, 'movement_type' => 'purchase', 'quantity' => 50, 'unit_cost' => 2.0]);
        Schtzie\FlowField\Tests\Fixtures\TestStockMovement::create(['item_id' => $item2->id, 'movement_type' => 'purchase', 'quantity' => 50, 'unit_cost' => 4.0]);
    });

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    Schtzie\FlowField\Tests\Fixtures\TestItem::withFlowFieldsBatch('weighted_avg_cost')->get();

    // 1 SELECT for items + 1 GROUP BY for wavg = 2 queries total
    expect($queryCount)->toBeLessThanOrEqual(3);

    expect((float) FlowFieldCache::get($item1, 'weighted_avg_cost'))->toBe(7.0);
    expect((float) FlowFieldCache::get($item2, 'weighted_avg_cost'))->toBe(3.0);
});

it('withFlowFieldSubqueries supports wavg calculation without N+1', function () {
    $item1 = Schtzie\FlowField\Tests\Fixtures\TestItem::create(['sku' => 'ITM-003', 'name' => 'Widget 3']);
    $item2 = Schtzie\FlowField\Tests\Fixtures\TestItem::create(['sku' => 'ITM-004', 'name' => 'Widget 4']);

    Schtzie\FlowField\Tests\Fixtures\TestStockMovement::withoutEvents(function () use ($item1, $item2) {
        Schtzie\FlowField\Tests\Fixtures\TestStockMovement::create(['item_id' => $item1->id, 'movement_type' => 'purchase', 'quantity' => 10, 'unit_cost' => 10.0]);
        Schtzie\FlowField\Tests\Fixtures\TestStockMovement::create(['item_id' => $item2->id, 'movement_type' => 'purchase', 'quantity' => 10, 'unit_cost' => 20.0]);
    });

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    Schtzie\FlowField\Tests\Fixtures\TestItem::whereIn('id', [$item1->id, $item2->id])->withFlowFieldSubqueries('weighted_avg_cost')->get();

    // 1 SELECT with correlated subquery = 1 query total
    expect($queryCount)->toBe(1);

    // file_put_contents('dump2.txt', "Count: " . $queryCount . "\n" . FlowFieldCache::get($item1, 'weighted_avg_cost') . "\n" . FlowFieldCache::get($item2, 'weighted_avg_cost'));

    expect((float) FlowFieldCache::get($item1, 'weighted_avg_cost'))->toBe(10.0);
    expect((float) FlowFieldCache::get($item2, 'weighted_avg_cost'))->toBe(20.0);
});

it('withFlowFieldsBatch supports whereHas filtering without N+1', function () {
    $c1 = TestCustomer::create(['name' => 'Cust 1']);
    $c2 = TestCustomer::create(['name' => 'Cust 2']);

    // Customer 1: Invoice 1 has lines with cost > 100
    $inv1 = Schtzie\FlowField\Tests\Fixtures\TestInvoice::create(['customer_id' => $c1->id, 'no' => 'I1', 'remaining_amount' => 500]);
    Schtzie\FlowField\Tests\Fixtures\TestInvoiceLine::create(['invoice_id' => $inv1->id, 'cost_amount' => 200]);

    // Customer 2: Invoice 2 has lines but cost < 100
    $inv2 = Schtzie\FlowField\Tests\Fixtures\TestInvoice::create(['customer_id' => $c2->id, 'no' => 'I2', 'remaining_amount' => 300]);
    Schtzie\FlowField\Tests\Fixtures\TestInvoiceLine::create(['invoice_id' => $inv2->id, 'cost_amount' => 50]);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    TestCustomer::withFlowFieldsBatch('high_cost_invoice_balance')->get();

    expect($queryCount)->toBeLessThanOrEqual(3); // 1 select models + 1 group by sum
    expect((float) FlowFieldCache::get($c1, 'high_cost_invoice_balance'))->toBe(500.0);
    expect((float) FlowFieldCache::get($c2, 'high_cost_invoice_balance'))->toBe(0.0);
});

it('withFlowFieldsBatch supports scope filtering without N+1', function () {
    $a1 = Schtzie\FlowField\Tests\Fixtures\TestGlAccount::create(['no' => '1000', 'name' => 'Cash']);
    $a2 = Schtzie\FlowField\Tests\Fixtures\TestGlAccount::create(['no' => '2000', 'name' => 'Sales']);

    // Account 1: Posted debit = 150 (Unposted 50 is ignored)
    Schtzie\FlowField\Tests\Fixtures\TestGlEntry::create(['gl_account_id' => $a1->id, 'debit_amount' => 150, 'posted' => true]);
    Schtzie\FlowField\Tests\Fixtures\TestGlEntry::create(['gl_account_id' => $a1->id, 'debit_amount' => 50, 'posted' => false]);

    // Account 2: Posted debit = 300
    Schtzie\FlowField\Tests\Fixtures\TestGlEntry::create(['gl_account_id' => $a2->id, 'debit_amount' => 300, 'posted' => true]);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    Schtzie\FlowField\Tests\Fixtures\TestGlAccount::withFlowFieldsBatch('total_debit')->get();

    expect($queryCount)->toBeLessThanOrEqual(3); // N+1 prevention check
    expect((float) FlowFieldCache::get($a1, 'total_debit'))->toBe(150.0);
    expect((float) FlowFieldCache::get($a2, 'total_debit'))->toBe(300.0);
});

it('withFlowFieldsBatch supports aging buckets without N+1', function () {
    $c1 = TestCustomer::create(['name' => 'Cust A']);
    $c2 = TestCustomer::create(['name' => 'Cust B']);

    // Aging 1_30 falls between 1 and 30 days ago.
    $due15DaysAgo = now()->subDays(15)->format('Y-m-d');
    $due40DaysAgo = now()->subDays(40)->format('Y-m-d'); // Outside bucket

    // Customer 1: 1 invoice in bucket (400), 1 outside (600)
    Schtzie\FlowField\Tests\Fixtures\TestInvoice::create(['customer_id' => $c1->id, 'no' => 'I3', 'due_date' => $due15DaysAgo, 'remaining_amount' => 400]);
    Schtzie\FlowField\Tests\Fixtures\TestInvoice::create(['customer_id' => $c1->id, 'no' => 'I4', 'due_date' => $due40DaysAgo, 'remaining_amount' => 600]);

    // Customer 2: 1 invoice in bucket (750)
    Schtzie\FlowField\Tests\Fixtures\TestInvoice::create(['customer_id' => $c2->id, 'no' => 'I5', 'due_date' => $due15DaysAgo, 'remaining_amount' => 750]);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    TestCustomer::whereIn('id', [$c1->id, $c2->id])->withFlowFieldsBatch('aging30')->get();

    expect($queryCount)->toBeLessThanOrEqual(3);
    expect((float) FlowFieldCache::get($c1, 'aging30'))->toBe(400.0);
    expect((float) FlowFieldCache::get($c2, 'aging30'))->toBe(750.0);
});

it('withFlowFieldsBatch falls back gracefully or calculates dynamic parameters', function () {
    $a1 = Schtzie\FlowField\Tests\Fixtures\TestGlAccount::create(['no' => '3000', 'name' => 'EUR Cash', 'currency_code' => 'EUR']);

    // TestGlEntry with EUR
    Schtzie\FlowField\Tests\Fixtures\TestGlEntry::create(['gl_account_id' => $a1->id, 'amount' => 500, 'currency_code' => 'EUR']);
    Schtzie\FlowField\Tests\Fixtures\TestGlEntry::create(['gl_account_id' => $a1->id, 'amount' => 200, 'currency_code' => 'USD']); // Ignored by filter

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    Schtzie\FlowField\Tests\Fixtures\TestGlAccount::where('id', $a1->id)->withFlowFieldsBatch('balance_fcy')->get();

    // The amount should be 500
    expect((float) FlowFieldCache::get($a1, 'balance_fcy'))->toBe(500.0);
});
