<?php

declare(strict_types=1);

/*
|==========================================================================
| laravel-flowfield — Configuration
|==========================================================================
|
| FlowFields are cache-backed computed aggregate properties for Eloquent
| models, inspired by Microsoft Dynamics / Navision's FlowField concept.
|
| Each FlowField is defined via a PHP Attribute on a method and is
| automatically calculated, cached, and invalidated whenever the
| underlying related records change.
|
| Publish this file with:
|   php artisan vendor:publish --tag=flowfield-config
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | 1. Cache Storage
    |--------------------------------------------------------------------------
    |
    | FlowField values are stored in Laravel's cache to avoid re-running
    | expensive aggregate queries on every request.
    |
    | store
    |   Which Laravel cache store to use. Set to null to use the application's
    |   default cache store (as defined in config/cache.php).
    |
    |   Common values: 'redis', 'memcached', 'dynamodb', 'file', 'array'
    |   ENV override:  FLOWFIELD_CACHE_STORE=redis
    |
    | prefix
    |   Every cache key is prefixed to avoid collisions with other cache data.
    |   Keys follow the pattern:  {prefix}:{table}:{id}:{field}
    |   Example:                  flowfield:customers:42:balance
    |
    | ttl
    |   Default cache lifetime in seconds for all FlowFields.
    |   null  → cache forever (recommended — invalidation is event-driven)
    |   300   → expire after 5 minutes regardless of mutations
    |   0     → disable caching for this field (always compute live)
    |
    |   Individual FlowFields can override this via the ttl: parameter
    |   on their #[FlowField] attribute.
    |
    | connection
    |   Named connection string for Redis or Memcached drivers.
    |   null uses the driver's default connection.
    |
    | serializer
    |   PHP extension used for serializing cached values at the driver level.
    |   Supported: 'php' (built-in) | 'igbinary' | 'msgpack'
    |   igbinary / msgpack are faster and produce smaller payloads but
    |   require the corresponding PHP extension to be installed.
    |
    | octane_l1
    |   When true, an additional in-process static PHP array acts as an L1
    |   (volatile) cache in front of the persistent store (L2). This gives
    |   sub-microsecond reads for values accessed more than once within the
    |   same Octane worker request.
    |   ⚠ Automatically reset on every request by OctaneFlowFieldListener.
    |   Irrelevant outside of Octane (FrankenPHP / RoadRunner / Swoole).
    |
    | octane_l1_ttl
    |   Maximum age (seconds) an L1 entry is trusted within a request.
    |   0 = trust indefinitely for the lifetime of the current request.
    |
    */
    'cache' => [
        'store' => env('FLOWFIELD_CACHE_STORE', 'redis'),
        'prefix' => 'flowfield',
        'ttl' => null,
        'connection' => null,
        'serializer' => 'msgpack',
        'octane_l1' => false,
        'octane_l1_ttl' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | 2. Cache Tag Strategy
    |--------------------------------------------------------------------------
    |
    | Cache tags allow all FlowField entries for a single model instance to be
    | invalidated atomically with a single cache call — without iterating over
    | every defined FlowField.
    |
    | tag_based: true   (default)
    |   FlowField cache entries are grouped under a tag per model instance:
    |     Tag pattern:  flowfield:{table}:{id}
    |     On delete:    Cache::tags(['flowfield:customers:42'])->flush()
    |   ✔ Efficient for models with many FlowFields.
    |   ✔ Supported by: Redis, Memcached.
    |   ✘ Not supported by: file, database, DynamoDB drivers.
    |
    | tag_based: false  (or auto-disabled)
    |   Falls back to explicit key-by-key deletion by iterating definitions.
    |   The ServiceProvider automatically sets this to false when it detects a
    |   non-taggable driver (file, database, dynamodb, null).
    |
    */
    'tag_based' => true,

    /*
    |--------------------------------------------------------------------------
    | 3. Auto Warm (Write-Through Caching)
    |--------------------------------------------------------------------------
    |
    | Controls whether FlowFields are immediately recalculated and re-cached
    | right after a related record mutation invalidates them.
    |
    | false  (default — lazy / pull-through)
    |   Cache is cleared on mutation. The next read triggers a fresh DB query
    |   and re-populates the cache. Best for write-heavy workloads.
    |
    | true   (eager / write-through)
    |   The new value is calculated synchronously during the same request that
    |   caused the invalidation. Cache is never cold for readers.
    |   ⚠ Adds a DB query overhead on every INSERT / UPDATE / DELETE.
    |   Best for read-heavy workloads where cache misses are unacceptable.
    |
    */
    'auto_warm' => false,

    /*
    |--------------------------------------------------------------------------
    | 4. Batch Aggregation  (N+1 Prevention)
    |--------------------------------------------------------------------------
    |
    | When loading FlowField values for a collection of models, a naïve
    | implementation would run one query per model per field (N × M queries).
    | Batch aggregation uses a single GROUP BY query per field instead.
    |
    | Usage:
    |   Customer::withFlowFieldsBatch('balance', 'entry_count')->paginate(50);
    |   // → 1 SELECT for models + 1 GROUP BY per field  (not 50 × 2 = 100)
    |
    | chunk_size
    |   Maximum number of model IDs included in each GROUP BY batch.
    |   For very large collections, the IDs are chunked to prevent hitting
    |   database IN clause limits or memory limits.
    |   Increase for better throughput; decrease for lower per-query memory.
    |
    */
    'batch' => [
        'chunk_size' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | 5. Query Deduplication
    |--------------------------------------------------------------------------
    |
    | When the same FlowField is accessed multiple times within a single
    | request (e.g. from two different services or view components), this
    | option prevents duplicate DB queries by keeping an in-memory map of
    | already-resolved values for the duration of the request.
    |
    | enabled: false  (default)
    |   Each cache miss triggers a fresh DB query. Safe for all environments
    |   including stateless queue workers.
    |
    | enabled: true
    |   FlowFieldQueryTracker stores resolved values in a static PHP array.
    |   Subsequent reads within the same request are served from memory.
    |   ⚠ The tracker is reset on every Octane request boundary automatically.
    |   ⚠ For queue workers, reset the tracker manually between jobs if needed.
    |
    */
    'query_deduplication' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | 6. Preload Strategy
    |--------------------------------------------------------------------------
    |
    | Automatic eager loading injects FlowField values into query results
    | without requiring an explicit scope call in every controller.
    |
    | enabled: false  (default)
    |   FlowFields are resolved on-demand when the attribute is first accessed.
    |
    | enabled: true
    |   FlowFields are automatically loaded for every collection query.
    |
    | strategy
    |   'batch'    → One GROUP BY query per field (efficient for large pages).
    |                Powered by withFlowFieldsBatch() internally.
    |   'subquery' → Correlated SELECT per field added to the main query.
    |                Reduces round-trips; best for small/paginated result sets.
    |                Powered by withFlowFieldSubqueries() internally.
    |
    */
    'preload' => [
        'enabled' => false,
        'strategy' => 'batch',
    ],

    /*
    |--------------------------------------------------------------------------
    | 7. Laravel Octane Compatibility
    |--------------------------------------------------------------------------
    |
    | In persistent worker environments (FrankenPHP, RoadRunner, Swoole),
    | PHP static properties survive across requests. FlowFieldCache uses
    | static properties for performance (tag detection, table name resolution,
    | L1 cache). These must be reset at every request boundary.
    |
    | OctaneFlowFieldListener handles this automatically when Octane is
    | detected. The settings below let you fine-tune its behaviour.
    |
    | reset_static_state  (default: true)
    |   Reset mutable per-request statics on every RequestReceived event:
    |     - $usesTagsCache     — re-detect tag support each request
    |     - $tableNameCache    — re-resolve model table names
    |     - $octaneL1          — flush volatile L1 cache entries
    |     - FlowFieldQueryTracker — reset deduplication map
    |
    | reset_registry  (default: false)
    |   Also reset $flowFieldRegistry (parsed FlowField attribute definitions).
    |   Leave false — PHP attribute reflection produces identical results on
    |   every request, so keeping the registry avoids repeated reflection cost.
    |   Only set true if you hot-reload model classes between requests.
    |
    | l1_cache.enabled  (default: false)
    |   Enable the volatile in-process L1 cache (see cache.octane_l1 above).
    |   Redundant with cache.octane_l1 — both must agree. This key is reserved
    |   for per-worker overrides in multi-server deployments.
    |
    | l1_cache.ttl
    |   Maximum seconds an L1 entry is trusted. 0 = valid for the full request.
    |
    | l1_cache.max_entries
    |   Safety cap on the number of entries held in the L1 static array.
    |   Prevents unbounded memory growth in long-running Swoole workers.
    |
    | preload_models
    |   List of model FQCNs to warm into cache on the WorkerStarting event.
    |   All records of each model are fetched and their FlowFields calculated
    |   once at worker boot — before the first request arrives.
    |   Example: [\App\Models\Currency::class, \App\Models\TaxRate::class]
    |
    */
    'octane' => [
        'reset_static_state' => true,
        'reset_registry' => false,
        'l1_cache' => [
            'enabled' => false,
            'ttl' => 0,
            'max_entries' => 10000,
        ],
        'preload_models' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | 8. API Resource Integration
    |--------------------------------------------------------------------------
    |
    | Settings for the FlowFieldResource trait and FlowFieldResourceCollection,
    | which make it easy to expose FlowField values through JSON API responses.
    |
    | auto_batch  (default: true)
    |   When a FlowFieldResourceCollection is serialized, automatically call
    |   batchCalcFlowFields() before the first resource is converted to JSON.
    |   This ensures the entire collection is warmed in N queries (one per
    |   field) rather than N × M individual queries.
    |
    | sparse_fieldsets  (default: true)
    |   Honour the JSON:API sparse fieldsets query parameter:
    |     GET /api/customers?fields[customers]=balance,entry_count
    |   Only the requested FlowFields are loaded and included in the response.
    |   Reduces unnecessary aggregation for consumers that only need a subset.
    |
    | openapi_types
    |   Maps each FlowField method to its corresponding OpenAPI data type.
    |   Used by FlowFieldOpenApi::schemaProperties() to generate schema
    |   definitions for tools like Swagger UI, Scribe, or L5-Swagger.
    |   Override individual entries if your FlowFields return custom types.
    |
    */
    'api' => [
        'auto_batch' => true,
        'sparse_fieldsets' => true,
        'openapi_types' => [
            'sum' => 'number',
            'count' => 'integer',
            'avg' => 'number',
            'min' => 'number',
            'max' => 'number',
            'exists' => 'boolean',
            'lookup' => 'string',
            'expression' => 'number',
            'multi' => 'object',
            'subquery' => 'number',
        ],
    ],

];
