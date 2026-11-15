<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_imports', function (Blueprint $table) {
            $table->id();
            $table->string('import_id')->unique();
            $table->string('file');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->jsonb('stats')->default('{}');
            $table->jsonb('applied')->default('{}');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_imports');
    }
};
