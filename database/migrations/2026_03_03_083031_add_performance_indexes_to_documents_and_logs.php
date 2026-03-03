<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * These indexes ensure O(log n) lookups even with 1M+ records.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Index for status filtering (very common for dashboard counters)
            $table->index('status', 'idx_status');
            
            // For the 'Bottleneck Detector' and analytics
            $table->index('created_at', 'idx_created_at');
        });

        Schema::table('document_logs', function (Blueprint $table) {
            // Index for fast document history lookups
            $table->index('document_id', 'idx_log_document_id');
            
            // For user activity tracking
            $table->index('user_id', 'idx_log_user_id');
            
            // Critical for Hash Chain verification (sequential access)
            $table->index('hash', 'idx_log_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('idx_status');
            $table->dropIndex('idx_created_at');
        });

        Schema::table('document_logs', function (Blueprint $table) {
            $table->dropIndex('idx_log_document_id');
            $table->dropIndex('idx_log_user_id');
            $table->dropIndex('idx_log_hash');
        });
    }
};
