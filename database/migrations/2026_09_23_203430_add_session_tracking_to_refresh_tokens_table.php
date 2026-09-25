<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->string('family_id')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->boolean('remember')->default(true);

            $table->index('family_id');
        });

        // Chaque token déjà émis devient sa propre famille (son propre id) :
        // un family_id NULL partagé entre plusieurs lignes regrouperait par
        // erreur des comptes différents lors d'une future révocation de
        // famille (voir ApiRefreshTokenController::handleReuse()).
        DB::table('refresh_tokens')->whereNull('family_id')->update(['family_id' => DB::raw('id')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropIndex(['family_id']);
            $table->dropColumn(['family_id', 'used_at', 'last_used_at', 'ip_address', 'user_agent', 'remember']);
        });
    }
};
