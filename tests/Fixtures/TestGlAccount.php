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
 * @property string $name
 * @property string $currency_code
 * @property float $balance
 * @property float $balance_fcy
 * @property float $total_debit
 * @property float $total_credit
 * @property float $net_change
 * @property float $gross_margin_pct
 */
class TestGlAccount extends Model
{
    use HasFlowFields;

    protected $guarded = [];

    /**
     * @return HasMany<TestGlEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(TestGlEntry::class, 'gl_account_id');
    }

    // 1. Simple sum
    #[FlowField(method: 'sum', relation: 'entries', column: 'amount')]
    protected function balance(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return $this->getFlowField('balance');
    }

    // 2. Multi-currency (dynamic parent lookup using `:currency_code`)
    #[FlowField(
        method: 'sum',
        relation: 'entries',
        column: 'amount',
        where: ['currency_code' => ':currency_code']
    )]
    protected function balanceFcy(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return $this->getFlowField('balance_fcy');
    }

    // 3. Scopes and FlowFilters
    #[FlowField(
        method: 'sum',
        relation: 'entries',
        column: 'debit_amount',
        scope: 'posted',
        flowFilters: ['posting_date', 'department_code']
    )]
    protected function totalDebit(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return $this->getFlowField('total_debit');
    }

    #[FlowField(
        method: 'sum',
        relation: 'entries',
        column: 'credit_amount',
        scope: 'posted',
        flowFilters: ['posting_date', 'department_code']
    )]
    protected function totalCredit(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return $this->getFlowField('total_credit');
    }

    // 4. Formula field (Net Change = Debit - Credit)
    #[FlowField(
        method: 'formula',
        expression: 'total_debit - total_credit'
    )]
    protected function netChange(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return $this->getFlowField('net_change');
    }

    // 5. Complex formula with divide by zero protection: (Revenue - COGS) / Revenue * 100
    // In this GL context, let's use: total_credit > 0 ? (total_credit - total_debit) / total_credit * 100 : 0
    #[FlowField(
        method: 'formula',
        expression: 'total_credit > 0 ? (total_credit - total_debit) / total_credit * 100 : 0'
    )]
    protected function grossMarginPct(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return $this->getFlowField('gross_margin_pct');
    }
}
