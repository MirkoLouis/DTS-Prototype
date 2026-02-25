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
        Schema::table('users', function (Blueprint $blueprint) {
            $blueprint->text('public_key')->nullable()->after('password');
            $blueprint->timestamp('security_key_set_at')->nullable()->after('public_key');
        });

        Schema::table('document_logs', function (Blueprint $blueprint) {
            $blueprint->text('signature')->nullable()->after('hash');
            // Adding a state hash to protect document details
            $blueprint->string('document_state_hash', 64)->nullable()->after('signature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $blueprint) {
            $blueprint->dropColumn(['public_key', 'security_key_set_at']);
        });

        Schema::table('document_logs', function (Blueprint $blueprint) {
            $blueprint->dropColumn(['signature', 'document_state_hash']);
        });
    }
};
