<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Schtzie\FlowField\Attributes\FlowField;
use Schtzie\FlowField\Concerns\HasFlowFields;

/**
 * @property int $id
 * @property string $no
 * @property string $status
 * @property string|null $due_date
 * @property float $total_amount
 * @property float $total_cost
 * @property float $gross_margin_pct
 */
class TestInvoice extends Model
{
    use HasFlowFields;
    use \Schtzie\FlowField\Concerns\InvalidatesFlowFields;

    protected $guarded = [];

    protected array $flowFieldTargets = [
        TestCustomer::class => 'customer_id',
    ];

    /**
     * @return HasMany<TestInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TestInvoiceLine::class, 'invoice_id');
    }

    // Standard total
    #[FlowField(method: 'sum', relation: 'lines', column: 'amount')]
    protected function totalAmount(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return $this->getFlowField('total_amount');
    }

    #[FlowField(method: 'sum', relation: 'lines', column: 'cost_amount')]
    protected function totalCost(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return $this->getFlowField('total_cost');
    }

    // Formula: (Total Amount - Total Cost) / Total Amount * 100
    #[FlowField(
        method: 'formula',
        expression: 'total_amount > 0 ? (total_amount - total_cost) / total_amount * 100 : 0'
    )]
    protected function grossMarginPct(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return $this->getFlowField('gross_margin_pct');
    }
}
