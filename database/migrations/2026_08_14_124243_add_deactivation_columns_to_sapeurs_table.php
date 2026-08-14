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
        Schema::table('sapeurs', function (Blueprint $table) {
            $table->timestamp('pending_deactivation_at')->nullable()->after('user_id');
            $table->timestamp('deactivated_at')->nullable()->after('pending_deactivation_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sapeurs', function (Blueprint $table) {
            $table->dropColumn(['pending_deactivation_at', 'deactivated_at']);
        });
    }
};
