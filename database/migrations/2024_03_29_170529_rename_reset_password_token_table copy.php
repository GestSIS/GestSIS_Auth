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
        Schema::rename('reset_password_tokens', 'password_reset_tokens');
        Schema::dropIfExists('password_resets');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('password_reset_tokens', 'reset_password_tokens');
    }
};
