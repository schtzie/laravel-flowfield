<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Schtzie\FlowField\Support\FlowFieldCalculator;
use Schtzie\FlowField\Support\FlowFieldDefinition;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function expressionDef(string $expression, array $where = []): FlowFieldDefinition
{
    return new FlowFieldDefinition(
        name: 'net_balance',
        method: 'expression',
        relation: 'entries',
        column: '*',
        where: $where,
        ttl: null,
        cacheKey: null,
        expression: $expression,
    );
}

// ---------------------------------------------------------------------------
// Method: expression
// ---------------------------------------------------------------------------

it('expression calculates COALESCE SUM correctly', function () {
    $customer = TestCustomer::create(['name' => 'Expr Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 300, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => -100, 'type' => 'credit',
    ]));

    $def = expressionDef('COALESCE(SUM(amount), 0)');
    $result = FlowFieldCalculator::calculate($customer, $def);

    expect((float) $result)->toBe(200.0);
});

it('expression returns zero via COALESCE on empty relation', function () {
    $customer = TestCustomer::create(['name' => 'Empty Expr Corp']);

    $def = expressionDef('COALESCE(SUM(amount), 0)');
    $result = FlowFieldCalculator::calculate($customer, $def);

    expect((float) $result)->toBe(0.0);
});

it('expression respects where conditions', function () {
    $customer = TestCustomer::create(['name' => 'Where Expr Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 500, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'credit',
    ]));

    $def = expressionDef('COALESCE(SUM(amount), 0)', ['type' => 'invoice']);
    $result = FlowFieldCalculator::calculate($customer, $def);

    expect((float) $result)->toBe(500.0);
});

it('expression throws when expression is not set', function () {
    $customer = TestCustomer::create(['name' => 'No Expr Corp']);

    $def = new FlowFieldDefinition(
        name: 'broken',
        method: 'expression',
        relation: 'entries',
        column: '*',
        where: [],
        ttl: null,
        cacheKey: null,
        expression: null,
    );

    FlowFieldCalculator::calculate($customer, $def);
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// Method: multi
// ---------------------------------------------------------------------------

it('multi aggregates multiple fields in a single query', function () {
    $customer = TestCustomer::create(['name' => 'Multi Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 200, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => -50, 'type' => 'credit',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $def = new FlowFieldDefinition(
        name: 'multi_stats',
        method: 'multi',
        relation: 'entries',
        column: '*',
        where: [],
        ttl: null,
        cacheKey: null,
        aggregates: [
            'total_sum' => ['sum', 'amount'],
            'total_count' => ['count', '*'],
            'max_amount' => ['max', 'amount'],
        ],
    );

    $results = FlowFieldCalculator::calculateMulti($customer, $def);

    expect($results)->toHaveKeys(['total_sum', 'total_count', 'max_amount']);
    expect((float) $results['total_sum'])->toBe(250.0);
    expect($results['total_count'])->toBe(3);
    expect((float) $results['max_amount'])->toBe(200.0);
});

it('multi returns zeros for empty relation', function () {
    $customer = TestCustomer::create(['name' => 'Empty Multi Corp']);

    $def = new FlowFieldDefinition(
        name: 'multi_stats',
        method: 'multi',
        relation: 'entries',
        column: '*',
        where: [],
        ttl: null,
        cacheKey: null,
        aggregates: [
            'total_sum' => ['sum', 'amount'],
            'total_count' => ['count', '*'],
        ],
    );

    $results = FlowFieldCalculator::calculateMulti($customer, $def);

    expect($results['total_sum'])->toBe(0);
    expect($results['total_count'])->toBe(0);
});

it('multi exists aggregate returns boolean', function () {
    $customer = TestCustomer::create(['name' => 'Exists Multi Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $def = new FlowFieldDefinition(
        name: 'multi_stats',
        method: 'multi',
        relation: 'entries',
        column: '*',
        where: [],
        ttl: null,
        cacheKey: null,
        aggregates: [
            'has_entries' => ['exists', '*'],
        ],
    );

    $results = FlowFieldCalculator::calculateMulti($customer, $def);

    expect($results['has_entries'])->toBeTrue();
});

it('multi uses single query for all aggregates', function () {
    $customer = TestCustomer::create(['name' => 'Query Count Corp']);

    for ($i = 0; $i < 5; $i++) {
        $cId = $customer->id;
        $amt = ($i + 1) * 10;
        TestEntry::withoutEvents(function () use ($cId, $amt) {
            TestEntry::create([
                'customer_id' => $cId,
                'amount' => $amt,
                'type' => 'invoice',
            ]);
        });
    }

    $def = new FlowFieldDefinition(
        name: 'multi_stats',
        method: 'multi',
        relation: 'entries',
        column: '*',
        where: [],
        ttl: null,
        cacheKey: null,
        aggregates: [
            'total_sum' => ['sum', 'amount'],
            'total_count' => ['count', '*'],
            'avg_amount' => ['avg', 'amount'],
            'min_amount' => ['min', 'amount'],
            'max_amount' => ['max', 'amount'],
        ],
    );

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    FlowFieldCalculator::calculateMulti($customer, $def);

    // One query for all 5 aggregates — not 5 separate queries
    expect($queryCount)->toBe(1);
});

// ---------------------------------------------------------------------------
// Method: subquery (closure)
// ---------------------------------------------------------------------------

it('subquery executes developer-provided closure', function () {
    $customer = TestCustomer::create(['name' => 'Subquery Corp']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 400, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'credit',
    ]));

    $def = new FlowFieldDefinition(
        name: 'net_sum',
        method: 'subquery',
        relation: 'entries',
        column: '*',
        where: [],
        ttl: null,
        cacheKey: null,
        query: fn ($query, $parent) => (float) $query->sum('amount'),
    );

    $result = FlowFieldCalculator::calculate($customer, $def);

    expect((float) $result)->toBe(500.0);
});

it('subquery closure receives relation query pre-configured', function () {
    $customer = TestCustomer::create(['name' => 'SQ Filter Corp']);

    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 50, 'type' => 'credit',
    ]));

    $def = new FlowFieldDefinition(
        name: 'invoice_count',
        method: 'subquery',
        relation: 'entries',
        column: '*',
        where: ['type' => 'invoice'],
        ttl: null,
        cacheKey: null,
        query: fn ($query, $parent) => $query->count(),
    );

    $result = FlowFieldCalculator::calculate($customer, $def);

    expect($result)->toBe(1);
});

it('subquery throws when query is not callable', function () {
    $customer = TestCustomer::create(['name' => 'No Query Corp']);

    $def = new FlowFieldDefinition(
        name: 'broken',
        method: 'subquery',
        relation: 'entries',
        column: '*',
        where: [],
        ttl: null,
        cacheKey: null,
        query: null,
    );

    FlowFieldCalculator::calculate($customer, $def);
})->throws(InvalidArgumentException::class);
