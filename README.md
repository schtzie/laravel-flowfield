# Laravel FlowField

[![Latest Version on Packagist](https://img.shields.io/packagist/v/schtzie/laravel-flowfield.svg?style=flat-square)](https://packagist.org/packages/schtzie/laravel-flowfield)
[![Total Downloads](https://img.shields.io/packagist/dt/schtzie/laravel-flowfield.svg?style=flat-square)](https://packagist.org/packages/schtzie/laravel-flowfield)
[![PHP Version](https://img.shields.io/packagist/php-v/schtzie/laravel-flowfield.svg?style=flat-square)](https://packagist.org/packages/schtzie/laravel-flowfield)
[![License](https://img.shields.io/packagist/l/schtzie/laravel-flowfield.svg?style=flat-square)](LICENSE.md)
[![Tests](https://img.shields.io/github/actions/workflow/status/schtzie/laravel-flowfield/tests.yml?style=flat-square&label=tests)](https://github.com/schtzie/laravel-flowfield/actions/workflows/tests.yml)


Cache-backed computed aggregate fields for Eloquent — inspired by Navision's FlowField concept.

## Based on
This project is a fork of
[openplain/laravel-flowfield](https://github.com/openplain/laravel-flowfield).
The original project is created and maintained by [Openplain](https://openplain.dev)
and is licensed under the MIT License.
This fork contains modifications and enhancements maintained by
[schtzie](https://github.com/schtzie).

## Why This Package?

When your `Customer` model needs to show a balance (sum of all ledger entries), or your `Item` needs `inventory_quantity` (sum of stock movements), you have two bad options: run the aggregate query every time (slow with thousands of entries), or store a denormalized total and keep it in sync manually (fragile — things drift, you build a "recalc" button).

In the late 1980s, three Danish engineers at PC&C (later Navision, now Microsoft Business Central) solved this exact problem. Their answer was **FlowFields** — virtual fields that compute aggregates on demand without storing the result in the database. Navision defined seven FlowField types: Sum, Count, Average, Min, Max, Exist, and Lookup. For Sum fields specifically, they built **SIFT** (Sum Index Field Technology) — pre-calculated indexes maintained on every write to make sum lookups instant. This concept has powered millions of ERP installations for over 35 years.

We brought the FlowField concept to Laravel. Where Navision uses SIFT indexes for sums and live queries for the rest, we use your cache layer (Redis/Memcached) as the performance layer for all aggregate types.

**Our Goal:** Declare aggregate fields as model attributes. Computed once, cached in Redis/Memcached, automatically invalidated when data changes. Instant reads. Zero maintenance. No stale data.

### Built on Proven Technology

- **Laravel Cache** — Uses your existing in-memory cache (Redis or Memcached) for instant lookups
- **PHP 8.1 Attributes** — Clean, declarative syntax for defining computed fields
- **Eloquent Events** — Automatic cache invalidation via model observers

## Features

- ⚡ **Instant Reads** — Aggregate values served from cache, not computed on every request
- 🔄 **Auto-Invalidation** — Cache busts automatically when related records change
- 🎯 **Declarative Syntax** — Define FlowFields with PHP attributes, no boilerplate
- 📊 **All 7 Navision Types** — `sum`, `count`, `avg`, `min`, `max`, `exists`, and `lookup`
- 🔍 **Lookup FlowField** — Fetch a single column value from a related record
- 🔗 **One-of-Many** — Inline `ofMany` selection without extra relation methods
- 🧩 **Polymorphic Relations** — Full `morphMany` / `morphOne` support with automatic invalidation
- 🔧 **Advanced Filtering** — Comparison operators, `whereNull`, `whereNotNull`, `between`, `like`
- 🔢 **Distinct Count** — `COUNT(DISTINCT column)` with a single flag
- 🚫 **No-Cache Mode** — Per-field opt-out for always-fresh values
- 📦 **Bulk Access** — `getFlowFieldValues()` for API serialization
- 🛡️ **Fault Tolerant** — Falls back to live queries if cache is unavailable
- 🔑 **Smart Invalidation** — Only invalidates when relevant columns actually change

## Requirements

- PHP 8.1 or higher
- Laravel 10, 11, 12, or 13

## Installation

```bash
composer require schtzie/laravel-flowfield
```

Optionally publish the configuration file:

```bash
php artisan vendor:publish --tag=flowfield-config
```

---

## Quick Start

### 1. Define FlowFields on Your Parent Model

Add the `HasFlowFields` trait and use the `#[FlowField]` attribute on accessor methods:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Openplain\FlowField\Attributes\FlowField;
use Openplain\FlowField\Concerns\HasFlowFields;

class Customer extends Model
{
    use HasFlowFields;

    public function ledgerEntries()
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    #[FlowField(method: 'sum', relation: 'ledgerEntries', column: 'amount')]
    protected function balance(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }

    #[FlowField(method: 'count', relation: 'ledgerEntries')]
    protected function entryCount(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }
}
```

### 2. Set Up Auto-Invalidation on Source Models

Add the `InvalidatesFlowFields` trait to models that contribute data:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Openplain\FlowField\Concerns\InvalidatesFlowFields;

class CustomerLedgerEntry extends Model
{
    use InvalidatesFlowFields;

    protected array $flowFieldTargets = [
        Customer::class => 'customer_id',
    ];
}
```

### 3. Use It

```php
$customer = Customer::find(1);

// First access: computes via SQL, caches the result
$customer->balance;    // 1250.75

// Second access: instant cache hit, no query
$customer->balance;    // 1250.75

// Create a new entry — cache is automatically invalidated
CustomerLedgerEntry::create(['customer_id' => 1, 'amount' => 500]);

// Next access: recalculates transparently
$customer->balance;    // 1750.75
```

---

## Usage

### The `#[FlowField]` Attribute — Full Reference

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `method` | `string` | *(required)* | `sum`, `count`, `avg`, `min`, `max`, `exists`, `lookup` |
| `relation` | `string` | *(required)* | Name of the Eloquent relationship method |
| `column` | `string` | `'*'` | Column to aggregate or fetch |
| `where` | `array` | `[]` | Filter conditions (see [Advanced Filtering](#advanced-filtering)) |
| `ttl` | `?int` | `null` | Cache TTL override; `0` = no-cache mode |
| `cacheKey` | `?string` | `null` | Custom cache key suffix |
| `distinct` | `bool` | `false` | Use `COUNT(DISTINCT column)` instead of `COUNT(column)` |
| `ofMany` | `string\|array\|null` | `null` | One-of-many selector for `lookup` fields |

---

### Aggregate Methods

All seven Navision FlowField types are supported:

```php
// Sum — add up all values (Navision: Sum FlowField)
#[FlowField(method: 'sum', relation: 'entries', column: 'amount')]
protected function balance(): Attribute { ... }

// Count — number of related records (Navision: Count FlowField)
#[FlowField(method: 'count', relation: 'entries')]
protected function entryCount(): Attribute { ... }

// Average (Navision: Average FlowField)
#[FlowField(method: 'avg', relation: 'entries', column: 'amount')]
protected function averageAmount(): Attribute { ... }

// Minimum (Navision: Min FlowField)
#[FlowField(method: 'min', relation: 'entries', column: 'amount')]
protected function minAmount(): Attribute { ... }

// Maximum (Navision: Max FlowField)
#[FlowField(method: 'max', relation: 'entries', column: 'amount')]
protected function maxAmount(): Attribute { ... }

// Exists — true/false whether any related records exist (Navision: Exist FlowField)
#[FlowField(method: 'exists', relation: 'entries')]
protected function hasEntries(): Attribute { ... }

// Lookup — fetch a single column value from a related record (Navision: Lookup FlowField)
#[FlowField(method: 'lookup', relation: 'latestEntry', column: 'status')]
protected function latestEntryStatus(): Attribute { ... }
```

> The `lookup` method calls `->value($column)` on the relation query. It returns `null` when no related record exists.

---

### Lookup FlowField

The **Lookup** is the 7th Navision FlowField type. It fetches a single column value from a related record — cached just like aggregates.

```php
class Customer extends Model
{
    use HasFlowFields;

    // Define a hasOne relation to use with lookup
    public function latestEntry()
    {
        return $this->hasOne(LedgerEntry::class)->latestOfMany();
    }

    // Fetch the 'status' of the most recent entry
    #[FlowField(method: 'lookup', relation: 'latestEntry', column: 'status')]
    protected function latestEntryStatus(): Attribute
    {
        return Attribute::make(get: fn () => null);
    }
}

$customer->latest_entry_status; // 'paid' | 'pending' | null
```

---

### One-of-Many (`ofMany`)

The `ofMany` parameter lets you declare "pick one record from many" lookups **without defining a separate relation method**. It converts the `hasMany` relation into a `hasOneOfMany` internally.

Only valid with `method: 'lookup'`.

```php
class Customer extends Model
{
    use HasFlowFields;

    // Only this one relation method is needed for all ofMany variants below:
    public function entries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    // Pick the most recently created entry → return its 'status'
    #[FlowField(method: 'lookup', relation: 'entries', column: 'status', ofMany: 'latest')]
    protected function latestEntryStatus(): Attribute { ... }

    // Pick the oldest entry → return its 'type'
    #[FlowField(method: 'lookup', relation: 'entries', column: 'type', ofMany: 'oldest')]
    protected function firstEntryType(): Attribute { ... }

    // Pick the entry with the highest amount → return that amount
    #[FlowField(method: 'lookup', relation: 'entries', column: 'amount', ofMany: 'max')]
    protected function largestEntryAmount(): Attribute { ... }

    // Pick the entry with the lowest amount → return that amount
    #[FlowField(method: 'lookup', relation: 'entries', column: 'amount', ofMany: 'min')]
    protected function smallestEntryAmount(): Attribute { ... }

    // Pick by one column, return a different column:
    // Find the entry with the max 'amount', return its 'type'
    #[FlowField(method: 'lookup', relation: 'entries', column: 'type', ofMany: ['amount', 'max'])]
    protected function typeOfLargestEntry(): Attribute { ... }
}
```

**`ofMany` values:**

| Value | Equivalent relation | Description |
|---|---|---|
| `'latest'` | `hasOne()->latestOfMany()` | Record with the highest primary key |
| `'oldest'` | `hasOne()->oldestOfMany()` | Record with the lowest primary key |
| `'max'` | `hasOne()->ofMany($column, 'max')` | Record with the maximum `$column` value |
| `'min'` | `hasOne()->ofMany($column, 'min')` | Record with the minimum `$column` value |
| `['col', 'agg']` | `hasOne()->ofMany($col, $agg)` | Custom aggregate column |

> **Note:** `ofMany` requires a regular `hasMany` relation. Polymorphic (`morphMany`) relations are not supported — define a dedicated `hasOne()->ofMany()` method instead.

---

### Advanced Filtering

The `where` parameter supports multiple syntaxes for powerful filtering:

#### Scalar equality

```php
// WHERE type = 'invoice'
#[FlowField(method: 'sum', relation: 'entries', column: 'amount', where: ['type' => 'invoice'])]
protected function totalInvoiced(): Attribute { ... }
```

#### Array → `whereIn`

```php
// WHERE status IN ('pending', 'processing')
#[FlowField(method: 'count', relation: 'orders', where: ['status' => ['pending', 'processing']])]
protected function openOrderCount(): Attribute { ... }
```

#### Comparison operators

```php
// WHERE amount > 0
#[FlowField(method: 'sum', relation: 'entries', column: 'amount', where: ['amount' => ['>', 0]])]
protected function positiveBalance(): Attribute { ... }

// WHERE amount >= 1000
#[FlowField(method: 'count', relation: 'entries', where: ['amount' => ['>=', 1000]])]
protected function largeEntryCount(): Attribute { ... }

// WHERE amount != 0
#[FlowField(method: 'count', relation: 'entries', where: ['amount' => ['!=', 0]])]
protected function nonZeroEntryCount(): Attribute { ... }

// WHERE reference LIKE 'INV-%'
#[FlowField(method: 'count', relation: 'entries', where: ['reference' => ['like', 'INV-%']])]
protected function invoiceCount(): Attribute { ... }
```

**Supported operators:** `>`, `<`, `>=`, `<=`, `!=`, `<>`, `like`, `between`, `not_null`

#### Between

```php
// WHERE amount BETWEEN 100 AND 999
#[FlowField(method: 'count', relation: 'entries', where: ['amount' => ['between', 100, 999]])]
protected function midRangeCount(): Attribute { ... }
```

#### `whereNull` / `whereNotNull`

```php
// WHERE voided_at IS NULL (active entries only)
#[FlowField(method: 'count', relation: 'entries', where: ['voided_at' => null])]
protected function activeEntryCount(): Attribute { ... }

// WHERE voided_at IS NOT NULL (voided entries)
#[FlowField(method: 'sum', relation: 'entries', column: 'amount', where: ['voided_at' => ['not_null']])]
protected function voidedTotal(): Attribute { ... }
```

#### Combining conditions

All conditions in `where` are ANDed together:

```php
#[FlowField(
    method: 'sum',
    relation: 'entries',
    column: 'amount',
    where: [
        'type'      => 'invoice',         // equality
        'amount'    => ['>', 0],           // operator
        'voided_at' => null,               // IS NULL
    ]
)]
protected function activeInvoiceTotal(): Attribute { ... }
```

---

### Distinct Count

Use `distinct: true` to count unique values with `COUNT(DISTINCT column)`:

```php
// COUNT(DISTINCT movement_type) — how many unique movement types were used
#[FlowField(method: 'count', relation: 'stockMovements', column: 'movement_type', distinct: true)]
protected function distinctMovementTypeCount(): Attribute { ... }

// COUNT(movement_type) — total number of movements (non-distinct)
#[FlowField(method: 'count', relation: 'stockMovements', column: 'movement_type')]
protected function movementCount(): Attribute { ... }
```

```php
$item->movement_count;              // 5 (five rows)
$item->distinct_movement_type_count; // 2 (only 'purchase' and 'sale' types used)
```

---

### No-Cache Mode (`ttl: 0`)

Set `ttl: 0` to bypass the cache entirely. The value is always calculated fresh from the database on every access — never stored in or read from cache.

```php
// Always live — useful for dashboards, real-time displays, or high-frequency writes
#[FlowField(method: 'sum', relation: 'entries', column: 'amount', ttl: 0)]
protected function liveBalance(): Attribute { ... }

// Normal cached field — served from cache
#[FlowField(method: 'sum', relation: 'entries', column: 'amount')]
protected function balance(): Attribute { ... }
```

```php
$customer->live_balance; // always queries DB — never cached
$customer->balance;      // served from cache, invalidated on write
```

> **When to use:** Raw SQL bulk operations that bypass Eloquent events, real-time metrics that change faster than your cache TTL, or fields that are rarely read but must always be exact.

---

### Bulk Access — `getFlowFieldValues()`

Retrieve multiple FlowField values as an associative array. Useful for API responses, audit logging, or comparing snapshots.

Cache behaviour is identical to normal attribute access — values are served from cache when warm.

```php
// All FlowFields on the model
$values = $customer->getFlowFieldValues();
// ['balance' => 1250.75, 'entry_count' => 12, 'has_entries' => true, ...]

// Specific fields only
$values = $customer->getFlowFieldValues('balance', 'entry_count');
// ['balance' => 1250.75, 'entry_count' => 12]
```

Perfect for API resources:

```php
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'name' => $this->name,
            ...$this->getFlowFieldValues('balance', 'entry_count', 'has_entries'),
        ];
    }
}
```

---

### Polymorphic Relations (`morphMany` / `morphOne`)

FlowFields work seamlessly on polymorphic parent models. Define FlowFields using a `morphMany` relation exactly as you would with `hasMany`:

```php
class Post extends Model
{
    use HasFlowFields;

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    #[FlowField(method: 'count', relation: 'comments')]
    protected function commentCount(): Attribute { ... }

    #[FlowField(method: 'sum', relation: 'comments', column: 'length')]
    protected function totalCommentLength(): Attribute { ... }

    #[FlowField(method: 'exists', relation: 'comments')]
    protected function hasComments(): Attribute { ... }

    #[FlowField(method: 'avg', relation: 'comments', column: 'length')]
    protected function averageCommentLength(): Attribute { ... }
}

class Video extends Model
{
    use HasFlowFields;

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    #[FlowField(method: 'count', relation: 'comments')]
    protected function commentCount(): Attribute { ... }
}
```

Laravel's morph-type constraint is automatically applied — `Post::comment_count` counts only comments where `commentable_type = 'App\Models\Post'`.

#### Polymorphic Child Invalidation — `morphFlowFieldTargets`

To invalidate the morph parent's FlowFields when a child changes, declare `morphFlowFieldTargets` on the child model:

```php
class Comment extends Model
{
    use InvalidatesFlowFields;

    // Regular parent invalidation (if any)
    protected array $flowFieldTargets = [];

    // Polymorphic parent invalidation:
    // Resolves 'commentable_type' + 'commentable_id' at runtime
    // to find the actual parent (Post, Video, etc.) and invalidate its FlowFields
    protected array $morphFlowFieldTargets = ['commentable'];
}
```

Each entry in `$morphFlowFieldTargets` is the base name of the morph relation. The trait automatically resolves `{name}_type` and `{name}_id` columns.

**What happens on each event:**

| Event | Behaviour |
|---|---|
| `created` | Invalidates the current morph parent |
| `deleted` | Invalidates the current morph parent |
| `updated` (relevant column) | Invalidates the current morph parent |
| `updated` (irrelevant column) | Skipped — smart invalidation applies |
| `updated` (morph target changed) | Invalidates **both** old and new parent |

**Morph parent reassignment** (moved from Post to Video):

```php
$comment->update([
    'commentable_type' => Video::class,
    'commentable_id'   => $video->id,
]);
// → Post's FlowFields invalidated (old parent)
// → Video's FlowFields invalidated (new parent)
```

#### Custom morph column names

If your morph columns don't follow the `{name}_type` / `{name}_id` convention, use the explicit pair syntax:

```php
protected array $morphFlowFieldTargets = [
    ['type' => 'subject_type', 'id' => 'subject_id'],
];
```

#### Multiple morph parents

A model can invalidate multiple morph parents simultaneously:

```php
protected array $morphFlowFieldTargets = [
    'commentable',  // resolves commentable_type + commentable_id
    'taggable',     // resolves taggable_type + taggable_id
];
```

---

### Automatic Invalidation (`InvalidatesFlowFields`)

The `InvalidatesFlowFields` trait hooks into Eloquent model events to bust caches automatically.

```php
class OrderLine extends Model
{
    use InvalidatesFlowFields;

    // Map: ParentModel::class => 'foreign_key_on_this_table'
    protected array $flowFieldTargets = [
        Order::class    => 'order_id',
        Customer::class => 'customer_id',
    ];
}
```

**Smart invalidation**: When a source record is updated, only FlowFields whose aggregated or filtered columns actually changed are invalidated. Updating `notes` (not in any FlowField) won't bust the cache.

**Foreign key changes**: If a record moves from one parent to another, **both** the old and new parent's caches are invalidated.

---

### Manual Operations

#### Force-recalculate (`calcFlowFields`)

Equivalent to NAV's `CALCFIELDS`. Computes and caches the value immediately:

```php
// Specific fields
$customer->calcFlowFields('balance', 'entry_count');

// All FlowFields
$customer->calcFlowFields();
```

#### Flush cache (`flushFlowFields`)

```php
// Specific fields
$customer->flushFlowFields('balance');

// All FlowFields for this record
$customer->flushFlowFields();
```

#### Get all definitions

```php
$definitions = $customer->getFlowFieldDefinitions();
// ['balance' => FlowFieldDefinition, 'entry_count' => FlowFieldDefinition, ...]

// Inspect a specific definition
$definitions['balance']->method;   // 'sum'
$definitions['balance']->relation; // 'ledgerEntries'
$definitions['balance']->column;   // 'amount'
$definitions['balance']->ofMany;   // null (or 'latest', 'max', etc.)
$definitions['balance']->distinct; // false
$definitions['balance']->ttl;      // null (uses global config)
```

---

### Eager Computation with `withFlowFields`

FlowFields are **not** auto-appended to `toArray()`/`toJson()` to avoid N+1 queries. Use `withFlowFields` to compute them eagerly for a collection:

```php
// Pre-warm specific fields for all results
$customers = Customer::withFlowFields('balance', 'entry_count')->get();

// All FlowFields
$customers = Customer::withFlowFields()->get();

// After this, all reads hit cache — zero extra queries
foreach ($customers as $customer) {
    echo $customer->balance;      // cache hit
    echo $customer->entry_count;  // cache hit
}
```

---

### Sorting by FlowField (`orderByFlowField`)

Sort query results by an aggregate value using a correlated subquery:

```php
// Highest balance first
$customers = Customer::orderByFlowField('balance', 'desc')->get();

// Combine with other conditions
$customers = Customer::where('active', true)
    ->orderByFlowField('entry_count', 'desc')
    ->paginate(25);
```

Works correctly with **polymorphic (`morphMany`) relations** — the subquery includes the morph-type constraint automatically, so ordering posts by `comment_count` won't bleed in counts from other morph types (videos, etc.).

---

## Configuration

All settings are in `config/flowfield.php`.

### Cache Store

```php
'cache' => [
    'store' => env('FLOWFIELD_CACHE_STORE', null), // null = Laravel default
],
```

### Cache TTL

```php
'cache' => [
    'ttl' => null, // null = forever (event-driven invalidation)
                   // Set e.g. 3600 as a safety net for raw SQL operations
],
```

> Individual FlowFields can override TTL via the `ttl` attribute parameter. Set `ttl: 0` on a field to disable caching entirely for that field.

### Cache Key Prefix

```php
'cache' => [
    'prefix' => 'flowfield',
],
```

Cache keys follow the pattern: `{prefix}:{table}:{id}:{field_name}`

Example: `flowfield:customers:42:balance`

### Auto-Warm

```php
'auto_warm' => false, // true = re-populate cache immediately after invalidation
```

> Enabling this adds a DB query on every write. Only enable if your reads are extremely latency-sensitive.

### Tag-Based Invalidation

```php
'tag_based' => true, // Uses cache tags (Redis/Memcached) for bulk invalidation
```

Falls back automatically to key-by-key invalidation for drivers that don't support tags.

---

## Artisan Commands

### Warm Cache

```bash
# Warm all models in app/Models
php artisan flowfield:warm

# Warm a specific model
php artisan flowfield:warm "App\Models\Customer"

# Warm a specific record
php artisan flowfield:warm "App\Models\Customer" --id=42

# Warm a specific field
php artisan flowfield:warm "App\Models\Customer" --field=balance
```

### Flush Cache

```bash
# Flush all FlowField caches
php artisan flowfield:flush

# Flush a specific model
php artisan flowfield:flush "App\Models\Customer"

# Flush a specific record
php artisan flowfield:flush "App\Models\Customer" --id=42
```

---

## Real-World Use Cases

### ERP: Customer Ledger (Navision-style)

```php
class Customer extends Model
{
    use HasFlowFields;

    public function ledgerEntries() { return $this->hasMany(LedgerEntry::class); }

    // Balance: sum of all amounts
    #[FlowField(method: 'sum', relation: 'ledgerEntries', column: 'amount')]
    protected function balance(): Attribute { ... }

    // Invoice total: sum of positive entries only
    #[FlowField(method: 'sum', relation: 'ledgerEntries', column: 'amount',
        where: ['amount' => ['>', 0]])]
    protected function totalInvoiced(): Attribute { ... }

    // Active entry count: only non-voided
    #[FlowField(method: 'count', relation: 'ledgerEntries', where: ['voided_at' => null])]
    protected function activeEntryCount(): Attribute { ... }

    // Latest transaction type (Lookup FlowField — the 7th Navision type)
    #[FlowField(method: 'lookup', relation: 'ledgerEntries', column: 'type', ofMany: 'latest')]
    protected function lastTransactionType(): Attribute { ... }

    // Live balance — always fresh, never cached (for real-time display)
    #[FlowField(method: 'sum', relation: 'ledgerEntries', column: 'amount', ttl: 0)]
    protected function liveBalance(): Attribute { ... }
}
```

### Inventory: Item Ledger Entry

```php
class Item extends Model
{
    use HasFlowFields;

    public function stockMovements() { return $this->hasMany(StockMovement::class); }

    // Net inventory quantity
    #[FlowField(method: 'sum', relation: 'stockMovements', column: 'quantity')]
    protected function inventoryQuantity(): Attribute { ... }

    // Inbound stock only
    #[FlowField(method: 'sum', relation: 'stockMovements', column: 'quantity',
        where: ['movement_type' => 'purchase'])]
    protected function totalReceived(): Attribute { ... }

    // How many distinct movement types have occurred
    #[FlowField(method: 'count', relation: 'stockMovements', column: 'movement_type', distinct: true)]
    protected function distinctMovementTypeCount(): Attribute { ... }

    // Movements in a specific quantity range
    #[FlowField(method: 'count', relation: 'stockMovements',
        where: ['quantity' => ['between', 1, 100]])]
    protected function smallBatchMovementCount(): Attribute { ... }
}
```

### SaaS: Tenant Metrics

```php
class Tenant extends Model
{
    use HasFlowFields;

    public function users()    { return $this->hasMany(User::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }

    #[FlowField(method: 'count', relation: 'users')]
    protected function userCount(): Attribute { ... }

    #[FlowField(method: 'sum', relation: 'invoices', column: 'amount',
        where: ['status' => 'paid'])]
    protected function totalRevenue(): Attribute { ... }

    #[FlowField(method: 'exists', relation: 'invoices', where: ['status' => 'overdue'])]
    protected function hasOverdueInvoices(): Attribute { ... }

    // Latest invoice status via Lookup
    #[FlowField(method: 'lookup', relation: 'invoices', column: 'status', ofMany: 'latest')]
    protected function latestInvoiceStatus(): Attribute { ... }
}
```

### Content Platform: Polymorphic Comments

```php
// Post and Video both receive comments polymorphically

class Post extends Model
{
    use HasFlowFields;

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    #[FlowField(method: 'count', relation: 'comments')]
    protected function commentCount(): Attribute { ... }

    #[FlowField(method: 'avg', relation: 'comments', column: 'rating')]
    protected function averageRating(): Attribute { ... }
}

class Video extends Model
{
    use HasFlowFields;

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    #[FlowField(method: 'count', relation: 'comments')]
    protected function commentCount(): Attribute { ... }
}

// Comment invalidates whichever parent it belongs to
class Comment extends Model
{
    use InvalidatesFlowFields;

    protected array $flowFieldTargets     = [];
    protected array $morphFlowFieldTargets = ['commentable'];

    public function commentable()
    {
        return $this->morphTo();
    }
}

// Usage
$post->comment_count;  // 12
$video->comment_count; // 3 (completely isolated — no cross-type bleed)

// Moving a comment: both old and new parent caches are invalidated
$comment->update(['commentable_type' => Video::class, 'commentable_id' => $video->id]);
```

---

## How Cache Keys Work

FlowField cache keys follow a predictable pattern:

```
{prefix}:{table}:{id}:{field_name}

Examples:
  flowfield:customers:42:balance
  flowfield:customers:42:entry_count
  flowfield:posts:7:comment_count
```

The `cacheKey` attribute parameter overrides `{field_name}`:

```php
#[FlowField(method: 'sum', relation: 'entries', column: 'amount', cacheKey: 'bal')]
protected function balance(): Attribute { ... }
// Key: flowfield:customers:42:bal
```

---

## Limitations

- **Eventual consistency** — There's a brief window between a write and cache invalidation. For most applications this is negligible.
- **In-memory cache recommended** — Redis or Memcached are strongly recommended. File or database cache stores defeat the purpose.
- **No cross-database relations** — FlowFields rely on standard Eloquent relationships within a single database connection.
- **`ofMany` is for `hasMany` only** — The `ofMany` shorthand does not support polymorphic (`morphMany`) relations. Define a dedicated `hasOne()->ofMany()` method instead.
- **`lookup` + `orderByFlowField` not supported** — Ordering by a single-value lookup via correlated subquery is not meaningful. Order by the related model's column directly.
- **Morph map aliases** — If you use Laravel's `Relation::morphMap()`, ensure the stored type string matches what `class_uses_recursive()` receives. Using full class names (the default) always works.

---

## Based on

This project is a fork of
[openplain/laravel-flowfield](https://github.com/openplain/laravel-flowfield).

The original project is created and maintained by [Openplain](https://openplain.dev)
and is licensed under the MIT License.

This fork contains modifications and enhancements maintained by
[schtzie](https://github.com/schtzie).

---

## Inspiration

This package implements the **FlowField** concept from Microsoft Dynamics NAV/Business Central — virtual fields that display computed aggregates (Sum, Count, Average, Min, Max, Exist, Lookup) without storing the result in the table.

The performance layer behind FlowFields has evolved over the decades:

- **SIFT** (Sum Index Field Technology) — the original optimization for Sum fields. On SQL Server, SIFT creates **indexed views** — materialized aggregates maintained automatically by the database engine on every insert/update/delete.
- **NCCI** (Nonclustered Columnstore Indexes) — the modern successor to SIFT in Business Central. A single columnstore index covers all aggregation scenarios with less write overhead.

We take a different approach: **cache as the performance layer**. Where Navision/Business Central relies on SQL Server features, we use Redis or Memcached. The tradeoff is simplicity — no database schema changes, works with any database — at the cost of eventual consistency during the brief invalidation window.

For the curious:
- [FlowFields overview](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/devenv-flowfields)
- [SIFT technology](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/devenv-sift-technology)
- [SIFT and SQL Server](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/devenv-sift-and-sql-server)
- [Migrating from SIFT to NCCI](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/developer/devenv-migrating-from-sift-to-ncci)

---

## Testing

```bash
composer test
```

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover a security vulnerability, please email security@openplain.dev. All security vulnerabilities will be promptly addressed.

**Please do not** open public issues for security vulnerabilities.

## Contributors

[![Contributors](https://contrib.rocks/image?repo=schtzie/laravel-flowfield)](https://github.com/schtzie/laravel-flowfield/graphs/contributors)

*Contributions of any size are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for details.*

---

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

Built with ❤️ by [schtzie](https://github.com/schtzie)
