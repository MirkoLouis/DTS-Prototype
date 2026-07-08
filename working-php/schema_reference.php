<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Combined all previous migrations into a single, clean schema definition,
     * including all the optimizations for the Document Tracking System (DTS) prototype.
     */
    public function up(): void
    {
        // 1. Core Laravel & Infrastructure Tables
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // 2. DTS Domain Tables (Base)
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->text('public_key')->nullable();
            $table->text('private_key')->nullable();
            $table->timestamp('security_key_set_at')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('role', ['officer', 'staff', 'admin'])->default('staff');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('user_public_key_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('public_key');
            $table->timestamp('activated_at');
            $table->timestamp('deactivated_at');
            $table->timestamps();

            $table->index(['user_id', 'activated_at', 'deactivated_at'], 'idx_user_key_period');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('purposes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_official')->default(false);
            $table->json('requirements')->nullable();
            $table->json('suggested_route')->nullable();
            $table->timestamps();
        });

        // 3. Workflow & Tracking Tables
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_code')->unique();
            $table->string('title')->nullable();
            $table->text('details')->nullable();
            $table->json('guest_info')->nullable();
            $table->string('district')->nullable();
            $table->string('department')->nullable();
            $table->foreignId('purpose_id')->constrained()->onDelete('cascade');
            $table->text('decline_reason')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('status')->default('pending');
            $table->json('finalized_route')->nullable();
            $table->integer('current_step')->nullable();
            
            $table->foreignId('current_department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();

            // Performance Indexes
            $table->index('status', 'idx_status');
            $table->index('created_at', 'idx_created_at');
            $table->index(['status', 'created_at'], 'idx_doc_status_created_composite');
            $table->index('current_department_id', 'idx_doc_current_dept');
            $table->index('released_at', 'idx_doc_released_at');
            $table->index('released_by_user_id', 'idx_doc_released_by');
        });

        Schema::create('document_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('action');
            $table->text('remarks')->nullable();
            $table->string('hash');
            $table->text('previous_hash')->nullable();
            $table->text('signature')->nullable();
            $table->string('document_state_hash', 64)->nullable();
            $table->json('document_snapshot')->nullable();
            $table->timestamps();

            // Performance Indexes
            $table->index('document_id', 'idx_log_document_id');
            $table->index('user_id', 'idx_log_user_id');
            $table->index('hash', 'idx_log_hash');
            $table->index('action', 'idx_log_action');
            $table->index('created_at', 'idx_log_created_at');
        });

        Schema::create('prediction_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('keyword')->index();
            $table->foreignId('department_id')->constrained('departments')->onDelete('cascade');
            $table->integer('weight')->default(1);
            $table->unsignedInteger('document_count')->default(1);
            $table->timestamps();
        });

        // 4. Analytics & Utilities
        Schema::create('daily_department_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->unsignedInteger('received_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('released_count')->default(0);
            $table->unsignedBigInteger('total_processing_seconds')->default(0);
            $table->timestamps();

            // Unique index for high-performance UPSERTS
            $table->unique(['department_id', 'date'], 'idx_dept_date_unique');
            
            // Fast date-based lookups for charts
            $table->index('date', 'idx_metrics_date');
        });

        Schema::create('database_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('connections');
            $table->decimal('avg_query_time_ms', 10, 4);
            $table->unsignedInteger('slow_queries');
            $table->timestamp('created_at')->useCurrent()->index('idx_metrics_created_at');
        });

        Schema::create('report_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('queued');
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('total_documents')->default(0);
            $table->string('file_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('integrity_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('queued');
            $table->unsignedInteger('progress')->default(0);
            $table->json('results')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrity_checks');
        Schema::dropIfExists('report_jobs');
        Schema::dropIfExists('database_metrics');
        Schema::dropIfExists('daily_department_metrics');
        Schema::dropIfExists('prediction_keywords');
        Schema::dropIfExists('document_logs');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('purposes');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('user_public_key_histories');
        Schema::dropIfExists('users');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
