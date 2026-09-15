<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Tests\Fixtures\TestGlAccount;
use Schtzie\FlowField\Tests\Fixtures\TestGlEntry;
use Schtzie\FlowField\Tests\Fixtures\TestInvoice;
use Schtzie\FlowField\Tests\Fixtures\TestInvoiceLine;

beforeEach(function () {
    FlowFieldCache::resetStaticState();
    DB::table('test_customers')->truncate();
    DB::table('test_gl_accounts')->truncate();
    DB::table('test_gl_entries')->truncate();
    DB::table('test_invoices')->truncate();
    DB::table('test_invoice_lines')->truncate();
    DB::table('test_items')->truncate();
    DB::table('test_stock_movements')->truncate();
});

it('calculates multi-currency balances via dynamic parent column reference', function () {
    $account = TestGlAccount::create([
        'no' => '1000',
        'name' => 'Cash - EUR',
        'currency_code' => 'EUR',
    ]);

    // Entry in matching currency
    TestGlEntry::create([
        'gl_account_id' => $account->id,
        'amount' => 100,
        'currency_code' => 'EUR',
    ]);

    // Entry in different currency (should be excluded from balanceFcy)
    TestGlEntry::create([
        'gl_account_id' => $account->id,
        'amount' => 50,
        'currency_code' => 'USD',
    ]);

    // Plain balance ignores currency
    expect((float) $account->balance)->toBe(150.0);

    // balanceFcy dynamically filters by parent's currency_code = 'EUR'
    expect((float) $account->balance_fcy)->toBe(100.0);
});

it('calculates complex formulas including net change and gross margin with divide by zero protection', function () {
    $account = TestGlAccount::create([
        'no' => '4000',
        'name' => 'Sales Revenue',
    ]);

    // Add 1000 credit (revenue), 200 debit (returns/cogs)
    TestGlEntry::create([
        'gl_account_id' => $account->id,
        'debit_amount' => 200,
        'credit_amount' => 1000,
        'posted' => true,
    ]);

    // Net Change formula = total_debit - total_credit
    expect((float) $account->total_debit)->toBe(200.0)
        ->and((float) $account->total_credit)->toBe(1000.0)
        ->and((float) $account->net_change)->toBe(-800.0);

    // Gross Margin Pct formula = total_credit > 0 ? (total_credit - total_debit) / total_credit * 100 : 0
    // (1000 - 200) / 1000 * 100 = 80
    expect((float) $account->gross_margin_pct)->toBe(80.0);

    // Test divide by zero protection (0 credit)
    $zeroAccount = TestGlAccount::create(['no' => '4001', 'name' => 'Empty']);
    expect((float) $zeroAccount->gross_margin_pct)->toBe(0.0);
});

it('utilizes eloquent scopes for posted_balance', function () {
    $account = TestGlAccount::create([
        'no' => '2000',
        'name' => 'Accounts Payable',
    ]);

    // Posted entry
    TestGlEntry::create([
        'gl_account_id' => $account->id,
        'debit_amount' => 50,
        'posted' => true,
    ]);

    // Unposted (draft) entry
    TestGlEntry::create([
        'gl_account_id' => $account->id,
        'debit_amount' => 100,
        'posted' => false,
    ]);

    // Scoped to 'posted' only (scopePosted on TestGlEntry)
    expect((float) $account->total_debit)->toBe(50.0);
});

it('supports complex formula aggregation on invoices', function () {
    $invoice = TestInvoice::create([
        'no' => 'INV-001',
        'status' => 'posted',
    ]);

    TestInvoiceLine::create([
        'invoice_id' => $invoice->id,
        'amount' => 500,
        'cost_amount' => 300,
    ]);

    TestInvoiceLine::create([
        'invoice_id' => $invoice->id,
        'amount' => 500,
        'cost_amount' => 450,
    ]);

    expect((float) $invoice->total_amount)->toBe(1000.0)
        ->and((float) $invoice->total_cost)->toBe(750.0);

    // Gross Margin = (1000 - 750) / 1000 * 100 = 25%
    expect((float) $invoice->gross_margin_pct)->toBe(25.0);
});

it('calculates aging buckets for invoices', function () {
    $customer = Schtzie\FlowField\Tests\Fixtures\TestCustomer::create(['name' => 'Acme']);

    // Current invoice (due in the future)
    TestInvoice::create([
        'customer_id' => $customer->id,
        'no' => 'INV-002',
        'due_date' => now()->addDays(5)->format('Y-m-d'),
        'remaining_amount' => 100,
    ]);

    // 1-30 days overdue invoice
    TestInvoice::create([
        'customer_id' => $customer->id,
        'no' => 'INV-003',
        'due_date' => now()->subDays(15)->format('Y-m-d'),
        'remaining_amount' => 300,
    ]);

    // 31+ days overdue invoice
    TestInvoice::create([
        'customer_id' => $customer->id,
        'no' => 'INV-004',
        'due_date' => now()->subDays(45)->format('Y-m-d'),
        'remaining_amount' => 500,
    ]);
    $q = $customer->invoices();
    $def = new Schtzie\FlowField\Support\FlowFieldDefinition('a', 'sum', 'invoices', 'remaining_amount', [], null, null, false, null, null, [], [], null, [], null, [], ['column' => 'due_date', 'bucket' => '1_30']);
    $def->applyWhere($q);

    expect((float) $customer->aging_current)->toBe(100.0)
        ->and((float) $customer->aging30)->toBe(300.0);
});

it('calculates whereHas for nested relation filtering', function () {
    $customer = Schtzie\FlowField\Tests\Fixtures\TestCustomer::create(['name' => 'Globex']);

    $inv1 = TestInvoice::create([
        'customer_id' => $customer->id,
        'no' => 'INV-005',
        'remaining_amount' => 1000,
    ]);
    TestInvoiceLine::create([
        'invoice_id' => $inv1->id,
        'cost_amount' => 50, // Not > 100
    ]);

    $inv2 = TestInvoice::create([
        'customer_id' => $customer->id,
        'no' => 'INV-006',
        'remaining_amount' => 2000,
    ]);
    TestInvoiceLine::create([
        'invoice_id' => $inv2->id,
        'cost_amount' => 150, // > 100
    ]);

    expect((float) $customer->high_cost_invoice_balance)->toBe(2000.0);
});

it('calculates weighted average cost (wavg)', function () {
    $item = Schtzie\FlowField\Tests\Fixtures\TestItem::create(['sku' => 'ITEM-001', 'name' => 'Widget']);

    // Purchase 1: 10 units at $5.00 each = $50.00
    Schtzie\FlowField\Tests\Fixtures\TestStockMovement::create([
        'item_id' => $item->id,
        'movement_type' => 'purchase',
        'quantity' => 10,
        'unit_cost' => 5.0,
    ]);

    // Purchase 2: 20 units at $8.00 each = $160.00
    Schtzie\FlowField\Tests\Fixtures\TestStockMovement::create([
        'item_id' => $item->id,
        'movement_type' => 'purchase',
        'quantity' => 20,
        'unit_cost' => 8.0,
    ]);

    // Sale: Should NOT be included in wavg because of where clause in FlowField
    Schtzie\FlowField\Tests\Fixtures\TestStockMovement::create([
        'item_id' => $item->id,
        'movement_type' => 'sale',
        'quantity' => -5,
        'unit_cost' => 10.0,
    ]);

    // Total Cost = 210, Total Qty = 30
    // WAVG = 210 / 30 = 7.00
    expect((float) $item->weighted_avg_cost)->toBe(7.0)
        ->and((float) $item->inventory_quantity)->toBe(25.0) // 10 + 20 - 5
        ->and((float) $item->inventory_value)->toBe(175.0); // 25 * 7.0
});
