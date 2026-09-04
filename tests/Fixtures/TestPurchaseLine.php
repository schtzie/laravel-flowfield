<?php

namespace Schtzie\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Schtzie\FlowField\Concerns\InvalidatesFlowFields;

/**
 * Mirrors Navision's Purchase Line / Vendor Ledger Entry table.
 *
 * Each row represents a billable line item from a vendor.
 * Status transitions (open → paid) are the primary write pattern:
 * when a line is paid, the vendor's outstanding_amount must drop and
 * paid_amount must rise — both are FlowFields that get cache-invalidated
 * automatically via InvalidatesFlowFields when this record is updated.
 */
class TestPurchaseLine extends Model
{
    use InvalidatesFlowFields;

    protected $table = 'test_purchase_lines';

    protected $guarded = [];

    /**
     * FlowField targets: changes here invalidate the parent Vendor's
     * cached aggregates via the vendor_id foreign key.
     */
    protected array $flowFieldTargets = [
        TestVendor::class => 'vendor_id',
    ];

    public function vendor()
    {
        return $this->belongsTo(TestVendor::class, 'vendor_id');
    }
}
