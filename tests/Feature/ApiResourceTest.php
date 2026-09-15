<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Schtzie\FlowField\Concerns\FlowFieldResource;
use Schtzie\FlowField\Http\Resources\FlowFieldResourceCollection;
use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Support\FlowFieldOpenApi;
use Schtzie\FlowField\Tests\Fixtures\TestCustomer;
use Schtzie\FlowField\Tests\Fixtures\TestEntry;

// ---------------------------------------------------------------------------
// Anonymous resource fixtures
// ---------------------------------------------------------------------------

function makeCustomerResource(TestCustomer $customer): object
{
    return new class($customer) extends JsonResource
    {
        use FlowFieldResource;
    };
}

// ---------------------------------------------------------------------------
// FlowFieldResource: whenFlowFieldLoaded
// ---------------------------------------------------------------------------

it('whenFlowFieldLoaded returns null when field is not in cache', function () {
    $customer = TestCustomer::create(['name' => 'Not Warm Corp']);
    $resource = makeCustomerResource($customer);

    expect($resource->whenFlowFieldLoaded('balance'))->toBeNull();
});

it('whenFlowFieldLoaded returns cached value when field is warm', function () {
    $customer = TestCustomer::create(['name' => 'Warm Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 750, 'type' => 'invoice',
    ]));
    $customer->balance; // Prime cache

    $resource = makeCustomerResource($customer);
    expect((float) $resource->whenFlowFieldLoaded('balance'))->toBe(750.0);
});

it('whenFlowFieldLoaded returns custom default when field not loaded', function () {
    $customer = TestCustomer::create(['name' => 'Default Corp']);
    $resource = makeCustomerResource($customer);

    expect($resource->whenFlowFieldLoaded('balance', 0))->toBe(0);
});

it('whenFlowFieldLoaded returns null for non-existent field', function () {
    $customer = TestCustomer::create(['name' => 'No Field Corp']);
    $resource = makeCustomerResource($customer);

    expect($resource->whenFlowFieldLoaded('non_existent_field'))->toBeNull();
});

// ---------------------------------------------------------------------------
// FlowFieldResource: flowFieldValues
// ---------------------------------------------------------------------------

it('flowFieldValues returns all FlowField values', function () {
    $customer = TestCustomer::create(['name' => 'Values Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 200, 'type' => 'invoice',
    ]));

    $resource = makeCustomerResource($customer);
    $values = $resource->flowFieldValues('balance', 'entry_count');

    expect($values)->toHaveKeys(['balance', 'entry_count']);
    expect((float) $values['balance'])->toBe(200.0);
    expect($values['entry_count'])->toBe(1);
});

it('flowFieldValues returns empty array for model without HasFlowFields', function () {
    // Use a plain stdClass (no HasFlowFields)
    $resource = new class(new stdClass) extends JsonResource
    {
        use FlowFieldResource;
    };

    expect($resource->flowFieldValues())->toBe([]);
});

// ---------------------------------------------------------------------------
// FlowFieldResource: mergeFlowFields
// ---------------------------------------------------------------------------

it('mergeFlowFields returns associative array suitable for merging', function () {
    $customer = TestCustomer::create(['name' => 'Merge Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 350, 'type' => 'invoice',
    ]));

    $resource = makeCustomerResource($customer);
    $merged = $resource->mergeFlowFields('balance');

    expect($merged)->toBeArray();
    expect($merged)->toHaveKey('balance');
    expect((float) $merged['balance'])->toBe(350.0);
});

// ---------------------------------------------------------------------------
// FlowFieldResource: requestedFlowFields (sparse fieldsets)
// ---------------------------------------------------------------------------

it('requestedFlowFields parses fields query param for model table', function () {
    $customer = TestCustomer::create(['name' => 'Sparse Corp']);
    $resource = makeCustomerResource($customer);

    $request = Request::create('/', 'GET', [
        'fields' => ['test_customers' => 'balance,entry_count'],
    ]);

    $fields = $resource->requestedFlowFields($request);

    expect($fields)->toContain('balance');
    expect($fields)->toContain('entry_count');
});

it('requestedFlowFields ignores non-FlowField columns', function () {
    $customer = TestCustomer::create(['name' => 'Filter Corp']);
    $resource = makeCustomerResource($customer);

    $request = Request::create('/', 'GET', [
        'fields' => ['test_customers' => 'balance,not_a_flowfield'],
    ]);

    $fields = $resource->requestedFlowFields($request);

    expect($fields)->toContain('balance');
    expect($fields)->not->toContain('not_a_flowfield');
});

it('requestedFlowFields returns empty when sparse fieldsets disabled', function () {
    config(['flowfield.api.sparse_fieldsets' => false]);

    $customer = TestCustomer::create(['name' => 'No Sparse Corp']);
    $resource = makeCustomerResource($customer);

    $request = Request::create('/', 'GET', [
        'fields' => ['test_customers' => 'balance'],
    ]);

    $fields = $resource->requestedFlowFields($request);

    expect($fields)->toBe([]);

    config(['flowfield.api.sparse_fieldsets' => true]);
});

// ---------------------------------------------------------------------------
// FlowFieldResourceCollection: auto batch
// ---------------------------------------------------------------------------

it('FlowFieldResourceCollection batch-loads FlowFields before serialization', function () {
    $c1 = TestCustomer::create(['name' => 'Coll 1']);
    $c2 = TestCustomer::create(['name' => 'Coll 2']);

    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c1->id, 'amount' => 100, 'type' => 'invoice']));
    TestEntry::withoutEvents(fn () => TestEntry::create(['customer_id' => $c2->id, 'amount' => 500, 'type' => 'invoice']));

    $resourceClass = new class(null) extends JsonResource
    {
        use FlowFieldResource;

        public function toArray($request): array
        {
            return ['balance' => $this->balance];
        }
    };

    $collection = new FlowFieldResourceCollection(
        TestCustomer::all()->map(fn ($c) => new $resourceClass($c))
    );

    // Force serialization
    $collection->toArray(request());

    // Both should now be cached
    expect(FlowFieldCache::get($c1, 'balance'))->not->toBeNull();
    expect(FlowFieldCache::get($c2, 'balance'))->not->toBeNull();
});

it('FlowFieldResourceCollection skips batch when auto_batch disabled', function () {
    config(['flowfield.api.auto_batch' => false]);

    $customer = TestCustomer::create(['name' => 'No Auto Corp']);
    TestEntry::withoutEvents(fn () => TestEntry::create([
        'customer_id' => $customer->id, 'amount' => 100, 'type' => 'invoice',
    ]));

    $resourceClass = new class(null) extends JsonResource
    {
        use FlowFieldResource;
    };

    $collection = new FlowFieldResourceCollection(
        TestCustomer::all()->map(fn ($c) => new $resourceClass($c))
    );

    $collection->toArray(request());

    // Cache should NOT be set since auto_batch is disabled
    expect(FlowFieldCache::get($customer, 'balance'))->toBeNull();

    config(['flowfield.api.auto_batch' => true]);
});

// ---------------------------------------------------------------------------
// FlowFieldOpenApi
// ---------------------------------------------------------------------------

it('FlowFieldOpenApi generates schema properties for all FlowFields', function () {
    $properties = FlowFieldOpenApi::schemaProperties(TestCustomer::class);

    expect($properties)->toBeArray();
    expect($properties)->toHaveKeys(['balance', 'entry_count', 'has_entries', 'total_invoiced']);

    expect($properties['balance']['type'])->toBe('number');
    expect($properties['balance']['readOnly'])->toBeTrue();
    expect($properties['entry_count']['type'])->toBe('integer');
    expect($properties['has_entries']['type'])->toBe('boolean');
});

it('FlowFieldOpenApi generates properties for specific fields only', function () {
    $properties = FlowFieldOpenApi::schemaProperties(TestCustomer::class, ['balance', 'entry_count']);

    expect($properties)->toHaveKeys(['balance', 'entry_count']);
    expect($properties)->not->toHaveKey('has_entries');
    expect($properties)->not->toHaveKey('total_invoiced');
});

it('FlowFieldOpenApi includes description for each property', function () {
    $properties = FlowFieldOpenApi::schemaProperties(TestCustomer::class);

    expect($properties['balance']['description'])->not->toBeEmpty();
    expect($properties['entry_count']['description'])->not->toBeEmpty();
});

it('FlowFieldOpenApi generates full schema object', function () {
    $schema = FlowFieldOpenApi::schema(TestCustomer::class, 'CustomerFlowFields');

    expect($schema)->toHaveKey('CustomerFlowFields');
    expect($schema['CustomerFlowFields']['type'])->toBe('object');
    expect($schema['CustomerFlowFields']['readOnly'])->toBeTrue();
    expect($schema['CustomerFlowFields']['properties'])->toBeArray();
});

it('FlowFieldOpenApi returns empty for models without HasFlowFields', function () {
    $properties = FlowFieldOpenApi::schemaProperties(stdClass::class);
    expect($properties)->toBe([]);
});
