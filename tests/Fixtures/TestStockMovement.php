<?php

namespace Schtzie\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Schtzie\FlowField\Concerns\InvalidatesFlowFields;

/**
 * Mirrors Navision's Item Ledger Entry table.
 *
 * Every stock movement (purchase receipt, sales shipment, adjustment)
 * creates an entry here. Writing to this table triggers SIFT updates in
 * Navision; in our implementation it triggers FlowField cache invalidation
 * on the parent Item, causing the next read to recalculate from scratch.
 *
 * Supports soft-delete (reversal entries in ERP are often logical deletes).
 */
class TestStockMovement extends Model
{
    use InvalidatesFlowFields;
    use SoftDeletes;

    protected $table = 'test_stock_movements';

    protected $guarded = [];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    /**
     * FlowField targets: when this ledger entry changes, invalidate
     * the parent Item's cached FlowFields via the item_id foreign key.
     */
    protected array $flowFieldTargets = [
        TestItem::class => 'item_id',
    ];

    public function item()
    {
        return $this->belongsTo(TestItem::class, 'item_id');
    }
}
