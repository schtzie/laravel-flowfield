# Changelog

All notable changes to `laravel-flowfield` will be documented in this file.

## Unreleased

### Added

#### New FlowField Types
- **`lookup` FlowField** — the 7th Navision type. Fetches a single column value from a related record via `->value($column)`. Returns `null` when no related record exists. Cached identically to aggregate FlowFields and invalidated on write.

#### One-of-Many (`ofMany` parameter)
- New `ofMany` parameter on `#[FlowField]` for declaring "pick one record from many" lookups **without a separate relation method**. Converts the `hasMany` relation to `hasOneOfMany` internally.
  - `ofMany: 'latest'` → `hasOne()->latestOfMany()` (newest by primary key)
  - `ofMany: 'oldest'` → `hasOne()->oldestOfMany()` (oldest by primary key)
  - `ofMany: 'max'` → `hasOne()->ofMany($column, 'max')` (highest value)
  - `ofMany: 'min'` → `hasOne()->ofMany($column, 'min')` (lowest value)
  - `ofMany: ['col', 'agg']` → `hasOne()->ofMany($col, $agg)` (custom aggregate column)

#### Advanced `where` Filtering
- **Comparison operators** in `where` conditions: `>`, `<`, `>=`, `<=`, `!=`, `<>`, `like`
  ```php
  where: ['amount' => ['>', 0]]
  where: ['reference' => ['like', 'INV-%']]
  ```
- **`between`** operator:
  ```php
  where: ['amount' => ['between', 100, 999]]
  ```
- **`whereNull`** — pass `null` as the value:
  ```php
  where: ['voided_at' => null]   // WHERE voided_at IS NULL
  ```
- **`whereNotNull`** — use the `not_null` keyword:
  ```php
  where: ['voided_at' => ['not_null']]  // WHERE voided_at IS NOT NULL
  ```

#### Distinct Count
- New `distinct: bool` parameter on `#[FlowField]`. When `true` on a `count` field, generates `COUNT(DISTINCT column)` instead of `COUNT(column)`:
  ```php
  #[FlowField(method: 'count', relation: 'movements', column: 'type', distinct: true)]
  ```

#### No-Cache Mode
- `ttl: 0` now fully disables caching for a specific FlowField. The value is always calculated fresh from the database on every access — never stored in or read from cache. Useful for real-time dashboards or fields updated by raw SQL.

#### Bulk Access
- New `getFlowFieldValues(string ...$fields): array` method on `HasFlowFields`. Returns all (or specified) FlowField values as an associative array. Cache behaviour is identical to normal attribute access.
  ```php
  $customer->getFlowFieldValues();                        // all fields
  $customer->getFlowFieldValues('balance', 'entry_count'); // specific fields
  ```

#### Polymorphic Relation Support
- **`morphMany` / `morphOne` aggregates** — all FlowField types (`sum`, `count`, `avg`, `min`, `max`, `exists`, `lookup`) now work correctly on polymorphic parent models. Laravel's morph-type constraint is automatically applied — counts and sums are isolated per morph type.
- **`morphFlowFieldTargets` property** on `InvalidatesFlowFields` — enables polymorphic child models to invalidate their morph parent's FlowFields automatically:
  ```php
  protected array $morphFlowFieldTargets = ['commentable'];
  // Resolves commentable_type + commentable_id at runtime
  ```
  - Supports standard naming convention (`'commentable'`) and explicit column-pair syntax (`['type' => 'col', 'id' => 'col']`)
  - Handles **morph parent reassignment** — invalidates both old and new parent when the morph target changes
  - Smart invalidation applies: irrelevant column updates are skipped

### Fixed

- **`orderByFlowField()` with polymorphic relations** — the correlated subquery now includes the morph-type constraint (`WHERE commentable_type = ?`), preventing aggregate bleed across different morph parent types (e.g., Post comments were previously mixing with Video comments).

### Changed

- `#[FlowField]` attribute now has two additional parameters: `distinct` (bool, default `false`) and `ofMany` (string|array|null, default `null`).
- `FlowFieldDefinition` constructor updated to include `distinct` and `ofMany` fields.

### Infrastructure

- Laravel 13 support added.
- `laravel/pint` added as a dev dependency for enforcing PSR-12 / Laravel code style.
- `pint.json` configuration added.

---

## 1.0.0 - 2026-03-17

- Initial release
- `#[FlowField]` attribute for declaring aggregate fields
- `HasFlowFields` trait with cache-backed attribute resolution
- `InvalidatesFlowFields` trait with automatic cache invalidation
- Support for `sum`, `count`, `avg`, `min`, `max`, `exists` aggregations
- `withFlowFields()` scope for eager computation
- `orderByFlowField()` scope for sorting by aggregate values
- `flowfield:warm` and `flowfield:flush` artisan commands
- Works with any Laravel cache driver
