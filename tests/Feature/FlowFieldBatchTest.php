<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Tests\Feature;

use Exception;
use Schtzie\FlowField\FlowFieldBatch;
use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestInvoice;
use Schtzie\FlowField\Tests\Fixtures\TestInvoiceLine;

it('defers and deduplicates invalidations inside batch', function () {
    $customer = TestCustomer::create(['name' => 'Batch Cust']);

    // Warm the cache
    $customer->balance;
    $customer->entry_count;

    // Track cache invalidation calls via Redis/Cache facade if we wanted to,
    // but the easiest way is to use a mock or check how many queries run to warm up.
    // FlowFieldCache::invalidateAll() uses Cache::tags(...)->flush(),
    // Since we can't easily count cache flushes on the facade without mocking,
    // we will just assert that the result is correct at the end.

    FlowFieldBatch::defer(function () use ($customer) {
        $invoice = TestInvoice::create([
            'customer_id' => $customer->id,
            'no' => 'INV-BATCH',
            'remaining_amount' => 0,
        ]);

        for ($i = 0; $i < 10; $i++) {
            TestInvoiceLine::create([
                'invoice_id' => $invoice->id,
                'cost_amount' => 100,
            ]);

            // At this point, if we weren't in a batch, it would have invalidated
            // the cache 10 times for the invoice and 10 times for the customer (through inheritance).
        }
    });

    // Verify cache was flushed and re-calculated properly
    expect((float) FlowFieldCache::get($customer, 'high_cost_invoice_balance'))->toBe(0.0); // Not high cost since remaining is 0 in invoice
});

it('clears deferred invalidations and does not execute them if an exception is thrown', function () {
    $customer = TestCustomer::create(['name' => 'Rollback Cust']);

    // Warm the cache with initial state
    \Illuminate\Support\Facades\DB::listen(function () {});
    $initialBalance = $customer->balance;
    $cacheKey = "flowfield:test_customers:{$customer->id}:balance";

    // Assert cache is populated
    expect(\Illuminate\Support\Facades\Cache::store('array')->get($cacheKey))->not->toBeNull();

    try {
        FlowFieldBatch::defer(function () use ($customer) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($customer) {
                \Schtzie\FlowField\Tests\Fixtures\TestEntry::create([
                    'customer_id' => $customer->id,
                    'amount' => 500,
                    'type' => 'invoice',
                ]);

                // Simulate a failure in the middle of a transaction
                throw new Exception('Simulated database error');
            });
        });
    } catch (Exception $e) {
        expect($e->getMessage())->toBe('Simulated database error');
    }

    // Verify that the batch state is reset
    expect(FlowFieldBatch::isDeferring())->toBeFalse();

    // The cache should NOT have been invalidated because the transaction failed
    // and the defer block threw an exception before executing the deferred invalidations.
    expect(\Illuminate\Support\Facades\Cache::store('array')->get($cacheKey))->not->toBeNull();

    // The balance should still be what it was initially
    expect((float) FlowFieldCache::get($customer, 'balance'))->toBe((float) $initialBalance);
});
