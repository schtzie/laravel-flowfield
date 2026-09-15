<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Schtzie\FlowField\Concerns\InvalidatesFlowFields;

/**
 * @property int $id
 * @property int $gl_account_id
 * @property float $amount
 * @property float $debit_amount
 * @property float $credit_amount
 * @property string $currency_code
 * @property string|null $posting_date
 * @property string|null $department_code
 * @property string|null $document_type
 * @property bool $posted
 */
class TestGlEntry extends Model
{
    use InvalidatesFlowFields;

    protected $guarded = [];

    protected $casts = [
        'posted' => 'boolean',
    ];

    protected array $flowFieldTargets = [
        TestGlAccount::class => 'gl_account_id',
    ];

    /**
     * @param  Builder<TestGlEntry>  $query
     * @return Builder<TestGlEntry>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('posted', true);
    }
}
