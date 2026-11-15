<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('external_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 14, 2);
            $table->timestamp('updated_at')->nullable();
            $table->primary(['product_id', 'price_type_id']);
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_type_id')->constrained()->restrictOnDelete();
            $table->string('number');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->string('external_id')->nullable()->unique();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('contract_prices', function (Blueprint $table) {
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 14, 2);
            $table->timestamps();
            $table->primary(['contract_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_prices');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('price_types');
    }
};
