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
        Schema::table('document_logs', function (Blueprint $table) {
            $table->renameColumn('integrity_hash', 'hash');
            $table->text('previous_hash')->after('hash')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_logs', function (Blueprint $table) {
            $table->renameColumn('hash', 'integrity_hash');
            $table->dropColumn('previous_hash');
        });
    }
};