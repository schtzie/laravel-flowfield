<?php

namespace Schtzie\FlowField\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Schtzie\FlowField\Attributes\FlowField;
use Schtzie\FlowField\Concerns\HasFlowFields;

class TestCustomer extends Model
{
    use HasFlowFields;

    protected $table = 'test_customers';

    protected $guarded = [];

    public function entries()
    {
        return $this->hasMany(TestEntry::class, 'customer_id');
    }

    /**
     * A hasOne relation pointing to the most recent entry — used by the
     * `lookup` FlowField below to fetch a single field value.
     */
    public function latestEntry()
    {
        return $this->hasOne(TestEntry::class, 'customer_id')->latestOfMany();
    }

    // -------------------------------------------------------------------------
    // Original FlowFields (unchanged)
    // -------------------------------------------------------------------------

    #[FlowField(method: 'sum', relation: 'entries', column: 'amount')]
    protected function balance(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    #[FlowField(method: 'sum', relation: 'entries', column: 'amount', where: ['type' => 'invoice'])]
    protected function totalInvoiced(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    #[FlowField(method: 'count', relation: 'entries')]
    protected function entryCount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    #[FlowField(method: 'exists', relation: 'entries')]
    protected function hasEntries(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    #[FlowField(method: 'avg', relation: 'entries', column: 'amount')]
    protected function averageAmount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    #[FlowField(method: 'min', relation: 'entries', column: 'amount')]
    protected function minAmount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    #[FlowField(method: 'max', relation: 'entries', column: 'amount')]
    protected function maxAmount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    // -------------------------------------------------------------------------
    // Feature 1 — Lookup FlowField (7th Navision type)
    // Fetches the 'type' column from the most recent related entry.
    // -------------------------------------------------------------------------

    #[FlowField(method: 'lookup', relation: 'latestEntry', column: 'type')]
    protected function latestEntryType(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    // -------------------------------------------------------------------------
    // Feature 2 — Comparison operators in where conditions
    // -------------------------------------------------------------------------

    /** Sum only entries where amount > 0 (debit-side only) */
    #[FlowField(method: 'sum', relation: 'entries', column: 'amount', where: ['amount' => ['>', 0]])]
    protected function positiveSum(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /** Count entries where amount is between 50 and 150 (inclusive) */
    #[FlowField(method: 'count', relation: 'entries', column: '*', where: ['amount' => ['between', 50, 150]])]
    protected function midRangeEntryCount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    // -------------------------------------------------------------------------
    // Feature 3 — whereNull / whereNotNull
    // -------------------------------------------------------------------------

    /** Count entries that have NOT been voided (voided_at IS NULL) */
    #[FlowField(method: 'count', relation: 'entries', column: '*', where: ['voided_at' => null])]
    protected function activeEntryCount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /** Sum of entries that have been explicitly voided (voided_at IS NOT NULL) */
    #[FlowField(method: 'sum', relation: 'entries', column: 'amount', where: ['voided_at' => ['not_null']])]
    protected function voidedSum(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    // -------------------------------------------------------------------------
    // Feature 4 — No-cache mode (ttl: 0)
    // Always calculated fresh from the DB; never stored in cache.
    // -------------------------------------------------------------------------

    #[FlowField(method: 'sum', relation: 'entries', column: 'amount', ttl: 0)]
    protected function liveBalance(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    // -------------------------------------------------------------------------
    // ofMany FlowField declarations (Feature A — inline one-of-many)
    // All use the existing `entries()` hasMany relation.
    // -------------------------------------------------------------------------

    /** Type of the most recently created entry (by primary key) */
    #[FlowField(method: 'lookup', relation: 'entries', column: 'type', ofMany: 'latest')]
    protected function latestEntryTypeInline(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /** Type of the oldest/first entry (by primary key) */
    #[FlowField(method: 'lookup', relation: 'entries', column: 'type', ofMany: 'oldest')]
    protected function oldestEntryType(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /** Amount of the entry with the highest amount value */
    #[FlowField(method: 'lookup', relation: 'entries', column: 'amount', ofMany: 'max')]
    protected function largestEntryAmount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /** Amount of the entry with the lowest amount value */
    #[FlowField(method: 'lookup', relation: 'entries', column: 'amount', ofMany: 'min')]
    protected function smallestEntryAmount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    /**
     * Type of the entry that has the largest amount.
     * Uses ofMany: ['amount', 'max'] — aggregate column differs from the returned column.
     */
    #[FlowField(method: 'lookup', relation: 'entries', column: 'type', ofMany: ['amount', 'max'])]
    protected function typeOfLargestEntry(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }
}
