<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('database_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('connections');
            $table->decimal('avg_query_time_ms', 10, 4);
            $table->unsignedInteger('slow_queries');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_metrics');
    }
};
