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
        Schema::create('prediction_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('keyword')->index(); // Indexing for faster searches
            $table->foreignId('department_id')->constrained('departments')->onDelete('cascade');
            $table->integer('weight')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prediction_keywords');
    }
};
