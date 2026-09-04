<?php

namespace Schtzie\FlowField\Tests\Unit;

use ReflectionClass;
use ReflectionMethod;
use Schtzie\FlowField\Attributes\FlowField;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\TestCase;

class FlowFieldAttributeTest extends TestCase
{
    public function test_attribute_can_be_instantiated_with_all_parameters(): void
    {
        $attr = new FlowField(
            method: 'sum',
            relation: 'entries',
            column: 'amount',
            where: ['type' => 'invoice'],
            ttl: 300,
            cacheKey: 'custom_key',
        );

        $this->assertEquals('sum', $attr->method);
        $this->assertEquals('entries', $attr->relation);
        $this->assertEquals('amount', $attr->column);
        $this->assertEquals(['type' => 'invoice'], $attr->where);
        $this->assertEquals(300, $attr->ttl);
        $this->assertEquals('custom_key', $attr->cacheKey);
    }

    public function test_attribute_has_sensible_defaults(): void
    {
        $attr = new FlowField(method: 'count', relation: 'entries');

        $this->assertEquals('*', $attr->column);
        $this->assertEquals([], $attr->where);
        $this->assertNull($attr->ttl);
        $this->assertNull($attr->cacheKey);
    }

    public function test_attributes_are_discovered_on_model(): void
    {
        $reflection = new ReflectionClass(TestCustomer::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PUBLIC);

        $flowFieldMethods = [];

        foreach ($methods as $method) {
            $attributes = $method->getAttributes(FlowField::class);

            if (! empty($attributes)) {
                $flowFieldMethods[$method->getName()] = $attributes[0]->newInstance();
            }
        }

        $this->assertArrayHasKey('balance', $flowFieldMethods);
        $this->assertArrayHasKey('totalInvoiced', $flowFieldMethods);
        $this->assertArrayHasKey('entryCount', $flowFieldMethods);
        $this->assertArrayHasKey('hasEntries', $flowFieldMethods);

        $this->assertEquals('sum', $flowFieldMethods['balance']->method);
        $this->assertEquals('count', $flowFieldMethods['entryCount']->method);
        $this->assertEquals('exists', $flowFieldMethods['hasEntries']->method);
    }

    public function test_attribute_where_conditions_are_preserved(): void
    {
        $reflection = new ReflectionClass(TestCustomer::class);
        $method = $reflection->getMethod('totalInvoiced');
        $attributes = $method->getAttributes(FlowField::class);
        $attr = $attributes[0]->newInstance();

        $this->assertEquals(['type' => 'invoice'], $attr->where);
    }
}
