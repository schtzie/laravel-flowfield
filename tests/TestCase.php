<?php

namespace Openplain\FlowField\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Openplain\FlowField\FlowFieldServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            FlowFieldServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('flowfield.cache.store', 'array');
        $app['config']->set('flowfield.cache.ttl', 3600);
        $app['config']->set('flowfield.tag_based', false);
    }

    protected function setUpDatabase(): void
    {
        // --- Original Customer / Entry domain ---
        Schema::create('test_customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('test_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('test_customers');
            $table->string('type')->default('invoice');
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamp('voided_at')->nullable(); // for whereNull/whereNotNull tests
            $table->timestamps();
            $table->softDeletes();
        });

        // --- Inventory domain: Item / Stock Movement (Item Ledger Entry) ---
        Schema::create('test_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('test_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('test_items');
            $table->string('movement_type'); // purchase | sale | adjustment
            $table->decimal('quantity', 12, 4)->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // --- Procurement domain: Vendor / Purchase Line (Vendor Ledger Entry) ---
        Schema::create('test_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('test_purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('test_vendors');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status')->default('open'); // open | paid
            $table->timestamps();
        });

        // --- Polymorphic domain: Post + Video / Comment (morphMany / morphOne) ---
        Schema::create('test_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('test_videos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('test_comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable'); // commentable_type + commentable_id
            $table->string('body');
            $table->integer('length')->default(0);
            $table->timestamps();
        });
    }
}
