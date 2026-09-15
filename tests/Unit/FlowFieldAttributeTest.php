<?php

declare(strict_types=1);

use Schtzie\FlowField\Attributes\FlowField;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;

it('attribute can be instantiated with all parameters', function () {
    $attr = new FlowField(
        method: 'sum',
        relation: 'entries',
        column: 'amount',
        where: ['type' => 'invoice'],
        ttl: 300,
        cacheKey: 'custom_key',
    );

    expect($attr->method)->toBe('sum');
    expect($attr->relation)->toBe('entries');
    expect($attr->column)->toBe('amount');
    expect($attr->where)->toBe(['type' => 'invoice']);
    expect($attr->ttl)->toBe(300);
    expect($attr->cacheKey)->toBe('custom_key');
});

it('attribute has sensible defaults', function () {
    $attr = new FlowField(method: 'count', relation: 'entries');

    expect($attr->column)->toBe('*');
    expect($attr->where)->toBe([]);
    expect($attr->ttl)->toBeNull();
    expect($attr->cacheKey)->toBeNull();
});

it('attributes are discovered on model methods', function () {
    $reflection = new ReflectionClass(TestCustomer::class);
    $methods = $reflection->getMethods(ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PUBLIC);

    $flowFieldMethods = [];
    foreach ($methods as $method) {
        $attributes = $method->getAttributes(FlowField::class);
        if (! empty($attributes)) {
            $flowFieldMethods[$method->getName()] = $attributes[0]->newInstance();
        }
    }

    expect($flowFieldMethods)->toHaveKeys(['balance', 'totalInvoiced', 'entryCount', 'hasEntries']);
    expect($flowFieldMethods['balance']->method)->toBe('sum');
    expect($flowFieldMethods['entryCount']->method)->toBe('count');
    expect($flowFieldMethods['hasEntries']->method)->toBe('exists');
});

it('attribute where conditions are preserved on model', function () {
    $reflection = new ReflectionClass(TestCustomer::class);
    $method = $reflection->getMethod('totalInvoiced');
    $attr = $method->getAttributes(FlowField::class)[0]->newInstance();

    expect($attr->where)->toBe(['type' => 'invoice']);
});
