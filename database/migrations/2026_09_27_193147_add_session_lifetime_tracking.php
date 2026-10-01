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
        Schema::table('users', function (Blueprint $table) {
            // Durée maximale d'une session (jours) ; NULL = défaut
            // (AuthSession::DEFAULT_MAX_DAYS). Réservé aux admins.
            $table->unsignedSmallInteger('session_max_days')->nullable();
            $table->foreignId('session_max_days_set_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('session_max_days_set_by');
            $table->dropColumn('session_max_days');
        });
    }
};
