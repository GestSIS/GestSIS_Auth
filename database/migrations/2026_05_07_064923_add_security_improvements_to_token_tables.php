<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Invalidate all existing tokens (they are plain text, need to be re-created as hashed)
        DB::table('refresh_tokens')->truncate();
        DB::table('password_reset_tokens')->truncate();

        // Improve refresh_tokens table
        Schema::table('refresh_tokens', function (Blueprint $table) {
            // Change token to fixed length for SHA-256 (64 hex characters)
            $table->string('token', 64)->change();

            // Make columns non-nullable
            $table->string('token', 64)->notNullable()->change();
            $table->timestamp('expire')->notNullable()->change();

            // Add unique constraint on token (prevent duplicates)
            $table->unique('token');

            // Add indexes for performance
            $table->index('expire');
            $table->index('user_id');
        });

        // Add cascade delete if not already present
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });

        // Improve password_reset_tokens table
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            // Change token to fixed length for SHA-256
            $table->string('token', 64)->change();

            // Make columns non-nullable
            $table->string('token', 64)->notNullable()->change();
            $table->timestamp('validite')->notNullable()->change();

            // Add unique constraint on token
            $table->unique('token');

            // Add indexes for performance
            $table->index('validite');
            $table->index('user_id');
        });

        // Improve users table - validate_email_token
        Schema::table('users', function (Blueprint $table) {
            // Change to fixed length and add index
            $table->string('validate_email_token', 64)->nullable()->change();
            $table->index('validate_email_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropIndex(['expire']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')
                ->references('id')
                ->on('users');
        });

        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropIndex(['validite']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['validate_email_token']);
        });
    }
};
