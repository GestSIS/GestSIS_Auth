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
        // Une ligne par session (appareil connecté). Son id est le `sid` des
        // access tokens. Le refresh token n'est pas stocké : il est signé
        // (RefreshTokenCodec) et porte l'id de session + la génération ; seule
        // la génération courante est gardée ici, ce qui suffit à détecter un
        // rejeu. La table `refresh_tokens` d'origine ne sert plus qu'à
        // reprendre les sessions ouvertes avant cette version.
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('refresh_generation')->default(0);
            $table->boolean('remember')->default(true);
            $table->timestamp('started_at');
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('idle_expires_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('two_factor_verified_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
    }
};
