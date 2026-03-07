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
        Schema::table('prediction_keywords', function (Blueprint $table) {
            $table->unsignedInteger('document_count')->default(1)->after('weight');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prediction_keywords', function (Blueprint $table) {
            $table->dropColumn('document_count');
        });
    }
};
