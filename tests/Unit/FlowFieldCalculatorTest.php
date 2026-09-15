<?php

declare(strict_types=1);

use Schtzie\FlowField\Support\FlowFieldCalculator;
use Schtzie\FlowField\Support\FlowFieldDefinition;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;

beforeEach(function () {
    $this->calculator = new FlowFieldCalculator;
    $this->customer = TestCustomer::create(['name' => 'Test']);

    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 100.50, 'type' => 'invoice']);
    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 200.75, 'type' => 'invoice']);
    TestEntry::create(['customer_id' => $this->customer->id, 'amount' => -50.25, 'type' => 'credit']);
});

function calcDef(string $method, string $column = 'amount', array $where = []): FlowFieldDefinition
{
    return new FlowFieldDefinition(
        name: 'test',
        method: $method,
        relation: 'entries',
        column: $column,
        where: $where,
        ttl: null,
        cacheKey: null,
    );
}

it('calculates sum aggregation', function () {
    $result = $this->calculator->calculate($this->customer, calcDef('sum'));
    expect((float) $result)->toBe(251.00);
});

it('calculates sum with where conditions', function () {
    $result = $this->calculator->calculate(
        $this->customer,
        calcDef('sum', 'amount', ['type' => 'invoice'])
    );
    expect((float) $result)->toBe(301.25);
});

it('calculates count aggregation', function () {
    $result = $this->calculator->calculate($this->customer, calcDef('count', '*'));
    expect($result)->toBe(3);
});

it('calculates avg aggregation', function () {
    $result = $this->calculator->calculate($this->customer, calcDef('avg'));
    expect((float) $result)->toEqualWithDelta(83.67, 0.01);
});

it('calculates min aggregation', function () {
    $result = $this->calculator->calculate($this->customer, calcDef('min'));
    expect((float) $result)->toBe(-50.25);
});

it('calculates max aggregation', function () {
    $result = $this->calculator->calculate($this->customer, calcDef('max'));
    expect((float) $result)->toBe(200.75);
});

it('exists aggregation returns true when records exist', function () {
    $result = $this->calculator->calculate($this->customer, calcDef('exists', '*'));
    expect($result)->toBeTrue();
});

it('exists aggregation returns false when no records', function () {
    $empty = TestCustomer::create(['name' => 'Empty']);
    $result = $this->calculator->calculate($empty, calcDef('exists', '*'));
    expect($result)->toBeFalse();
});

it('where with array values uses whereIn', function () {
    $result = $this->calculator->calculate(
        $this->customer,
        calcDef('count', '*', ['type' => ['invoice', 'credit']])
    );
    expect($result)->toBe(3);
});

it('invalid method throws InvalidArgumentException', function () {
    $this->calculator->calculate($this->customer, calcDef('invalid_method'));
})->throws(InvalidArgumentException::class);
