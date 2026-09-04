<?php

namespace Schtzie\FlowField\Tests\Unit;

use Illuminate\Database\Eloquent\Builder;
use Schtzie\FlowField\Support\FlowFieldDefinition;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\TestCase;

/**
 * Unit tests for FlowFieldDefinition::applyWhere()
 *
 * Verifies that all supported where syntax variants generate the correct
 * SQL conditions without going through the full FlowField pipeline.
 * We use TestCustomer's real DB to inspect generated SQL via ->toSql().
 */
class FlowFieldDefinitionTest extends TestCase
{
    private function makeDefinition(array $where): FlowFieldDefinition
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

    private function queryWithWhere(array $where): Builder
    {
        $customer = TestCustomer::create(['name' => 'SQL Test']);
        $query = $customer->entries()->getQuery();
        $this->makeDefinition($where)->applyWhere($query);

        return $query;
    }

    // -------------------------------------------------------------------------
    // Scalar equality (existing behaviour — must remain unchanged)
    // -------------------------------------------------------------------------

    public function test_scalar_value_produces_equality_condition(): void
    {
        $sql = $this->queryWithWhere(['type' => 'invoice'])->toSql();
        $this->assertStringContainsString('"type" = ?', $sql);
    }

    // -------------------------------------------------------------------------
    // Array → whereIn (existing behaviour — must remain unchanged)
    // -------------------------------------------------------------------------

    public function test_plain_array_produces_where_in(): void
    {
        $sql = $this->queryWithWhere(['type' => ['invoice', 'credit']])->toSql();
        $this->assertStringContainsString('in (?', $sql);
    }

    // -------------------------------------------------------------------------
    // whereNull / whereNotNull (Feature 3)
    // -------------------------------------------------------------------------

    public function test_null_value_produces_where_null(): void
    {
        $sql = $this->queryWithWhere(['voided_at' => null])->toSql();
        $this->assertStringContainsString('is null', strtolower($sql));
    }

    public function test_not_null_operator_produces_where_not_null(): void
    {
        $sql = $this->queryWithWhere(['voided_at' => ['not_null']])->toSql();
        $this->assertStringContainsString('is not null', strtolower($sql));
    }

    // -------------------------------------------------------------------------
    // Comparison operators (Feature 2)
    // -------------------------------------------------------------------------

    public function test_greater_than_operator(): void
    {
        $sql = $this->queryWithWhere(['amount' => ['>', 0]])->toSql();
        $this->assertStringContainsString('"amount" > ?', $sql);
    }

    public function test_less_than_operator(): void
    {
        $sql = $this->queryWithWhere(['amount' => ['<', 100]])->toSql();
        $this->assertStringContainsString('"amount" < ?', $sql);
    }

    public function test_greater_than_or_equal_operator(): void
    {
        $sql = $this->queryWithWhere(['amount' => ['>=', 50]])->toSql();
        $this->assertStringContainsString('"amount" >= ?', $sql);
    }

    public function test_less_than_or_equal_operator(): void
    {
        $sql = $this->queryWithWhere(['amount' => ['<=', 50]])->toSql();
        $this->assertStringContainsString('"amount" <= ?', $sql);
    }

    public function test_not_equal_operator(): void
    {
        $sql = $this->queryWithWhere(['type' => ['!=', 'credit']])->toSql();
        $this->assertStringContainsString('"type" != ?', $sql);
    }

    public function test_like_operator(): void
    {
        $sql = $this->queryWithWhere(['type' => ['like', 'inv%']])->toSql();
        $this->assertStringContainsString('"type" like ?', strtolower($sql));
    }

    // -------------------------------------------------------------------------
    // Between (Feature 2)
    // -------------------------------------------------------------------------

    public function test_between_operator_produces_between_condition(): void
    {
        $sql = $this->queryWithWhere(['amount' => ['between', 50, 150]])->toSql();
        $this->assertStringContainsString('between', strtolower($sql));
    }

    // -------------------------------------------------------------------------
    // Multiple conditions in same where array — all ANDed
    // -------------------------------------------------------------------------

    public function test_multiple_conditions_are_all_applied(): void
    {
        $sql = $this->queryWithWhere([
            'type' => 'invoice',
            'amount' => ['>', 0],
        ])->toSql();

        $this->assertStringContainsString('"type" = ?', $sql);
        $this->assertStringContainsString('"amount" > ?', $sql);
    }

    public function test_mix_of_operator_and_plain_conditions(): void
    {
        $sql = $this->queryWithWhere([
            'type' => ['invoice', 'credit'], // whereIn
            'voided_at' => null,                  // whereNull
            'amount' => ['>=', 10],             // operator
        ])->toSql();

        $this->assertStringContainsString('in (?', $sql);
        $this->assertStringContainsString('is null', strtolower($sql));
        $this->assertStringContainsString('"amount" >= ?', $sql);
    }

    // -------------------------------------------------------------------------
    // getRelevantColumns — operator syntax still tracks the column name
    // -------------------------------------------------------------------------

    public function test_get_relevant_columns_includes_operator_where_columns(): void
    {
        $def = $this->makeDefinition(['amount' => ['>', 0], 'voided_at' => null]);
        $columns = $def->getRelevantColumns();

        $this->assertContains('amount', $columns);
        $this->assertContains('voided_at', $columns);
    }
}
