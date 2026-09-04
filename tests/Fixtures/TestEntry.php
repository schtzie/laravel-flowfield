<?php

namespace Schtzie\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Schtzie\FlowField\Concerns\InvalidatesFlowFields;

class TestEntry extends Model
{
    use InvalidatesFlowFields;
    use SoftDeletes;

    protected $table = 'test_entries';

    protected $guarded = [];

    protected array $flowFieldTargets = [
        TestCustomer::class => 'customer_id',
    ];

    public function customer()
    {
        return $this->belongsTo(TestCustomer::class, 'customer_id');
    }
}
