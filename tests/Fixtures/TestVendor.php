<?php

namespace Openplain\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Openplain\FlowField\Attributes\FlowField;
use Openplain\FlowField\Concerns\HasFlowFields;

/**
 * Mirrors Navision's Vendor table with Vendor Ledger Entry FlowFields.
 *
 * In Business Central, a Vendor's payable balance is a Sum FlowField over
 * the Vendor Ledger Entry table. The "Outstanding Amount" field shows only
 * open (unpaid) entries, while "Paid Amount" shows closed entries.
 * This is a core AP (Accounts Payable) reporting pattern in all ERP systems.
 */
class TestVendor extends Model
{
    use HasFlowFields;

    protected $table = 'test_vendors';

    protected $guarded = [];

    public function purchaseLines()
    {
        return $this->hasMany(TestPurchaseLine::class, 'vendor_id');
    }

    /**
     * Total payable balance for open (unpaid) purchase lines.
     * Navision equivalent: Vendor."Outstanding Amount" (Sum, open entries only)
     */
    #[FlowField(method: 'sum', relation: 'purchaseLines', column: 'amount', where: ['status' => 'open'])]
    protected function outstandingAmount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Total amount settled/paid across all closed purchase lines.
     * Navision equivalent: Vendor."Purchases (LCY)" filtered to closed entries
     */
    #[FlowField(method: 'sum', relation: 'purchaseLines', column: 'amount', where: ['status' => 'paid'])]
    protected function paidAmount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Number of purchase lines currently awaiting payment.
     * Navision equivalent: Count FlowField over open Vendor Ledger Entries
     */
    #[FlowField(method: 'count', relation: 'purchaseLines', column: '*', where: ['status' => 'open'])]
    protected function openOrderCount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Whether this vendor has any outstanding (unpaid) lines.
     * Navision equivalent: Exist FlowField — useful for AP aging lists
     */
    #[FlowField(method: 'exists', relation: 'purchaseLines', column: '*', where: ['status' => 'open'])]
    protected function hasOpenOrders(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Largest single purchase line amount (for credit limit checks).
     * Navision equivalent: Max FlowField — used in credit management
     */
    #[FlowField(method: 'max', relation: 'purchaseLines', column: 'amount')]
    protected function largestOrder(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Average purchase line value for vendor spend analytics.
     * Navision equivalent: Average FlowField (one of the 7 Navision types)
     */
    #[FlowField(method: 'avg', relation: 'purchaseLines', column: 'amount')]
    protected function averageOrderValue(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }
}
