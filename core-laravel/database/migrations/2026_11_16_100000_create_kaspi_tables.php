<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 16)->default('portal')->index()->after('number');
        });

        Schema::create('kaspi_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 16)->default('one_c');
            $table->string('api_url')->nullable();
            $table->text('api_token')->nullable();
            $table->string('merchant_id')->nullable();
            $table->string('company_name')->nullable();
            $table->string('pricelist_token', 64);
            $table->foreignId('price_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('notify_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('poll_minutes')->default(1);
            $table->unsignedSmallInteger('sync_days')->default(30);
            $table->boolean('auto_accept')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kaspi_stores', function (Blueprint $table) {
            $table->id();
            $table->string('store_id', 64)->unique();
            $table->string('name');
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('kaspi_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->decimal('price', 14, 2)->nullable();
            $table->boolean('is_published')->default(true)->index();
            $table->string('kaspi_master_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('kaspi_orders', function (Blueprint $table) {
            $table->id();
            $table->string('kaspi_id')->unique();
            $table->string('code')->unique();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 32)->index();
            $table->string('status', 48)->index();
            $table->string('delivery_mode', 48);
            $table->boolean('is_kaspi_delivery')->default(false);
            $table->boolean('express')->default(false);
            $table->string('payment_mode', 32)->nullable();
            $table->unsignedSmallInteger('credit_term')->nullable();
            $table->boolean('signature_required')->default(false);
            $table->boolean('pre_order')->default(false);
            $table->decimal('total_price', 14, 2);
            $table->decimal('delivery_cost', 14, 2)->default(0);
            $table->decimal('delivery_cost_for_seller', 14, 2)->default(0);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('store_id', 64)->nullable();
            $table->timestamp('kaspi_created_at')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('planned_delivery_at')->nullable();
            $table->timestamp('courier_planned_at')->nullable();
            $table->timestamp('courier_transmitted_at')->nullable();
            $table->string('waybill_url')->nullable();
            $table->string('waybill_number')->nullable();
            $table->unsignedSmallInteger('number_of_space')->nullable();
            $table->string('cancellation_reason', 64)->nullable();
            $table->text('cancellation_comment')->nullable();
            $table->boolean('returned_to_warehouse')->default(false);
            $table->timestamp('code_sent_at')->nullable();
            $table->boolean('stock_reserved')->default(false);
            $table->string('problem')->nullable();
            $table->jsonb('raw')->default('{}');
            $table->timestamps();
        });

        Schema::create('kaspi_order_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kaspi_order_id')->constrained()->cascadeOnDelete();
            $table->string('kaspi_entry_id');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable()->index();
            $table->string('name');
            $table->string('category')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('base_price', 14, 2);
            $table->decimal('total_price', 14, 2);
            $table->string('store_id', 64)->nullable();
            $table->unique(['kaspi_order_id', 'kaspi_entry_id']);
        });

        Schema::create('kaspi_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kaspi_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->string('text', 500);
            $table->timestamp('created_at');
        });

        Schema::create('kaspi_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 16);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kaspi_sync_runs');
        Schema::dropIfExists('kaspi_order_events');
        Schema::dropIfExists('kaspi_order_entries');
        Schema::dropIfExists('kaspi_orders');
        Schema::dropIfExists('kaspi_products');
        Schema::dropIfExists('kaspi_stores');
        Schema::dropIfExists('kaspi_settings');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
