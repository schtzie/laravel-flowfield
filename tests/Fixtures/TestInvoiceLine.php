<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Schtzie\FlowField\Concerns\InvalidatesFlowFields;

/**
 * @property int $id
 * @property int $invoice_id
 * @property float $amount
 * @property float $cost_amount
 */
class TestInvoiceLine extends Model
{
    use InvalidatesFlowFields;

    protected $guarded = [];

    protected array $flowFieldTargets = [
        TestInvoice::class => 'invoice_id',
    ];
}
