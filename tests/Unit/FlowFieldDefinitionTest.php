<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Schtzie\FlowField\Support\FlowFieldDefinition;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;

// Helper to build a minimal definition with where conditions
function makeTestDefinition(array $where): FlowFieldDefinition
{
    return new FlowFieldDefinition(
        name: 'test',
        method: 'count',
        relation: 'entries',
        column: '*',
        where: $where,
        ttl: null,
        cacheKey: null,
        distinct: false,
    );
}

function queryWithWhere(array $where): Builder
{
    $customer = TestCustomer::create(['name' => 'SQL Test']);
    $query = $customer->entries()->getQuery();
    makeTestDefinition($where)->applyWhere($query);

    return $query;
}

// --- Scalar equality ---

it('scalar value produces equality condition', function () {
    $sql = queryWithWhere(['type' => 'invoice'])->toSql();
    expect($sql)->toContain('"type" = ?');
});

// --- Array → whereIn ---

it('plain array produces whereIn', function () {
    $sql = queryWithWhere(['type' => ['invoice', 'credit']])->toSql();
    expect($sql)->toContain('in (?');
});

// --- whereNull / whereNotNull ---

it('null value produces whereNull', function () {
    $sql = strtolower(queryWithWhere(['voided_at' => null])->toSql());
    expect($sql)->toContain('is null');
});

it('not_null operator produces whereNotNull', function () {
    $sql = strtolower(queryWithWhere(['voided_at' => ['not_null']])->toSql());
    expect($sql)->toContain('is not null');
});

// --- Comparison operators ---

it('greater-than operator produces correct SQL', function () {
    $sql = queryWithWhere(['amount' => ['>', 0]])->toSql();
    expect($sql)->toContain('"amount" > ?');
});

it('less-than operator produces correct SQL', function () {
    $sql = queryWithWhere(['amount' => ['<', 100]])->toSql();
    expect($sql)->toContain('"amount" < ?');
});

it('greater-than-or-equal operator produces correct SQL', function () {
    $sql = queryWithWhere(['amount' => ['>=', 50]])->toSql();
    expect($sql)->toContain('"amount" >= ?');
});

it('less-than-or-equal operator produces correct SQL', function () {
    $sql = queryWithWhere(['amount' => ['<=', 50]])->toSql();
    expect($sql)->toContain('"amount" <= ?');
});

it('not-equal operator produces correct SQL', function () {
    $sql = queryWithWhere(['type' => ['!=', 'credit']])->toSql();
    expect($sql)->toContain('"type" != ?');
});

it('like operator produces correct SQL', function () {
    $sql = strtolower(queryWithWhere(['type' => ['like', 'inv%']])->toSql());
    expect($sql)->toContain('"type" like ?');
});

// --- Between ---

it('between operator produces BETWEEN condition', function () {
    $sql = strtolower(queryWithWhere(['amount' => ['between', 50, 150]])->toSql());
    expect($sql)->toContain('between');
});

// --- Multiple conditions ---

it('multiple conditions are all applied', function () {
    $sql = queryWithWhere(['type' => 'invoice', 'amount' => ['>', 0]])->toSql();
    expect($sql)->toContain('"type" = ?');
    expect($sql)->toContain('"amount" > ?');
});

it('mix of operator and plain conditions all apply', function () {
    $sql = queryWithWhere([
        'type' => ['invoice', 'credit'],
        'voided_at' => null,
        'amount' => ['>=', 10],
    ])->toSql();

    expect($sql)->toContain('in (?');
    expect(strtolower($sql))->toContain('is null');
    expect($sql)->toContain('"amount" >= ?');
});

// --- getRelevantColumns ---

it('getRelevantColumns includes operator where columns', function () {
    $def = makeTestDefinition(['amount' => ['>', 0], 'voided_at' => null]);
    $columns = $def->getRelevantColumns();

    expect($columns)->toContain('amount');
    expect($columns)->toContain('voided_at');
});
