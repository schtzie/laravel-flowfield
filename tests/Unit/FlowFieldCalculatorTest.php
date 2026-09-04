<?php

namespace Schtzie\FlowField\Tests\Unit;

use Schtzie\FlowField\Support\FlowFieldCalculator;
use Schtzie\FlowField\Support\FlowFieldDefinition;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;
use Schtzie\FlowField\Tests\TestCase;

class FlowFieldCalculatorTest extends TestCase
{
    protected FlowFieldCalculator $calculator;

    protected TestCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new FlowFieldCalculator;
        $this->customer = TestCustomer::create(['name' => 'Test']);

        TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 100.50, 'type' => 'invoice']);
        TestEntry::create(['customer_id' => $this->customer->id, 'amount' => 200.75, 'type' => 'invoice']);
        TestEntry::create(['customer_id' => $this->customer->id, 'amount' => -50.25, 'type' => 'credit']);
    }

    public function test_sum_aggregation(): void
    {
        $definition = new FlowFieldDefinition(
            name: 'balance',
            method: 'sum',
            relation: 'entries',
            column: 'amount',
            where: [],
            ttl: null,
            cacheKey: null,
        );

        $result = $this->calculator->calculate($this->customer, $definition);

        $this->assertEquals(251.00, (float) $result);
    }

    public function test_sum_with_where_conditions(): void
    {
        $definition = new FlowFieldDefinition(
            name: 'total_invoiced',
            method: 'sum',
            relation: 'entries',
            column: 'amount',
            where: ['type' => 'invoice'],
            ttl: null,
            cacheKey: null,
        );

        $result = $this->calculator->calculate($this->customer, $definition);

        $this->assertEquals(301.25, (float) $result);
    }

    public function test_count_aggregation(): void
    {
        $definition = new FlowFieldDefinition(
            name: 'entry_count',
            method: 'count',
            relation: 'entries',
            column: '*',
            where: [],
            ttl: null,
            cacheKey: null,
        );

        $result = $this->calculator->calculate($this->customer, $definition);

        $this->assertEquals(3, $result);
    }

    public function test_avg_aggregation(): void
    {
        $definition = new FlowFieldDefinition(
            name: 'average_amount',
            method: 'avg',
            relation: 'entries',
            column: 'amount',
            where: [],
            ttl: null,
            cacheKey: null,
        );

        $result = $this->calculator->calculate($this->customer, $definition);

        $this->assertEqualsWithDelta(83.67, (float) $result, 0.01);
    }

    public function test_min_aggregation(): void
    {
        $definition = new FlowFieldDefinition(
            name: 'min_amount',
            method: 'min',
            relation: 'entries',
            column: 'amount',
            where: [],
            ttl: null,
            cacheKey: null,
        );

        $result = $this->calculator->calculate($this->customer, $definition);

        $this->assertEquals(-50.25, (float) $result);
    }

    public function test_max_aggregation(): void
    {
        $definition = new FlowFieldDefinition(
            name: 'max_amount',
            method: 'max',
            relation: 'entries',
            column: 'amount',
            where: [],
            ttl: null,
            cacheKey: null,
        );

        $result = $this->calculator->calculate($this->customer, $definition);

        $this->assertEquals(200.75, (float) $result);
    }

    public function test_exists_aggregation_returns_true(): void
    {
        $definition = new FlowFieldDefinition(
            name: 'has_entries',
            method: 'exists',
            relation: 'entries',
            column: '*',
            where: [],
            ttl: null,
            cacheKey: null,
        );

        $result = $this->calculator->calculate($this->customer, $definition);

        $this->assertTrue($result);
    }

    public function test_exists_aggregation_returns_false(): void
    {
        $emptyCustomer = TestCustomer::create(['name' => 'Empty']);

        $definition = new FlowFieldDefinition(
            name: 'has_entries',
            method: 'exists',
            relation: 'entries',
            column: '*',
            where: [],
            ttl: null,
            cacheKey: null,
        );

        $result = $this->calculator->calculate($emptyCustomer, $definition);

        $this->assertFalse($result);
    }

    public function test_where_with_array_values(): void
    {
        $definition = new FlowFieldDefinition(
            name: 'invoice_and_credit',
            method: 'count',
            relation: 'entries',
            column: '*',
            where: ['type' => ['invoice', 'credit']],
            ttl: null,
            cacheKey: null,
        );

        $result = $this->calculator->calculate($this->customer, $definition);

        $this->assertEquals(3, $result);
    }

    public function test_invalid_method_throws_exception(): void
    {
        $definition = new FlowFieldDefinition(
            name: 'invalid',
            method: 'invalid_method',
            relation: 'entries',
            column: 'amount',
            where: [],
            ttl: null,
            cacheKey: null,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculate($this->customer, $definition);
    }
}
