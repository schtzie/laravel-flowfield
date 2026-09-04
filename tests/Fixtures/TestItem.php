<?php

namespace Schtzie\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Schtzie\FlowField\Attributes\FlowField;
use Schtzie\FlowField\Concerns\HasFlowFields;

/**
 * Mirrors Navision's Item table with Item Ledger Entry FlowFields.
 *
 * In Business Central, an Item's Inventory is a Sum FlowField over the
 * Item Ledger Entry table, filtered by different Quantity types.
 * SIFT (Sum Index Field Technology) keeps these sums instant on every write.
 * Here, our cache layer serves the same role.
 */
class TestItem extends Model
{
    use HasFlowFields;

    protected $table = 'test_items';

    protected $guarded = [];

    public function stockMovements()
    {
        return $this->hasMany(TestStockMovement::class, 'item_id');
    }

    /**
     * Total inventory quantity across ALL movement types.
     * Navision equivalent: Item."Inventory" (Sum FlowField, no filter)
     */
    #[FlowField(method: 'sum', relation: 'stockMovements', column: 'quantity')]
    protected function inventoryQuantity(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Quantity received via purchase orders.
     * Navision equivalent: Item."Purchases (Qty.)" (Sum FlowField, Movement Type filter)
     */
    #[FlowField(method: 'sum', relation: 'stockMovements', column: 'quantity', where: ['movement_type' => 'purchase'])]
    protected function purchasedQuantity(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Quantity shipped via sales orders (stored as negative in ledger).
     * Navision equivalent: Item."Sales (Qty.)" (Sum FlowField, Movement Type filter)
     */
    #[FlowField(method: 'sum', relation: 'stockMovements', column: 'quantity', where: ['movement_type' => 'sale'])]
    protected function soldQuantity(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Quantity changed via manual inventory adjustments.
     * Navision equivalent: Item."Net Change" with Adjustment entry type filter
     */
    #[FlowField(method: 'sum', relation: 'stockMovements', column: 'quantity', where: ['movement_type' => 'adjustment'])]
    protected function adjustmentQuantity(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Total number of ledger entries for this item.
     * Navision equivalent: Count FlowField over Item Ledger Entry
     */
    #[FlowField(method: 'count', relation: 'stockMovements', column: '*')]
    protected function movementCount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Date of the most recent stock movement posting.
     * Navision equivalent: Item."Last Entry Date" (Max FlowField)
     */
    #[FlowField(method: 'max', relation: 'stockMovements', column: 'posted_at')]
    protected function lastTransactionDate(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Whether this item has any ledger entries at all.
     * Navision equivalent: Exist FlowField — Item."Has Entries"
     */
    #[FlowField(method: 'exists', relation: 'stockMovements', column: '*')]
    protected function hasStockMovements(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    // -------------------------------------------------------------------------
    // Feature 6 — distinct: true on count FlowFields
    // -------------------------------------------------------------------------

    /**
     * Count of distinct movement types used for this item.
     * e.g., 3 if all of purchase / sale / adjustment have occurred.
     * Navision equivalent: COUNT(DISTINCT Entry Type) on Item Ledger Entry.
     */
    #[FlowField(method: 'count', relation: 'stockMovements', column: 'movement_type', distinct: true)]
    protected function distinctMovementTypeCount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }
}
