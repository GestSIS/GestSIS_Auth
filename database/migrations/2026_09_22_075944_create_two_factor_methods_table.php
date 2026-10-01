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
        // Une ligne par méthode 2FA d'un compte (application TOTP, clé
        // WebAuthn). Une méthode n'est active qu'une fois confirmée : une ligne
        // sans `confirmed_at` est un enrôlement en cours, jamais accepté comme
        // second facteur. « Le compte a du 2FA » = au moins une ligne confirmée.
        Schema::create('two_factor_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name')->nullable();

            // TOTP
            $table->text('secret')->nullable();
            $table->unsignedBigInteger('last_used_timestep')->nullable();

            // WebAuthn
            $table->string('credential_id')->nullable()->unique();
            $table->text('public_key')->nullable();
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('aaguid')->nullable();
            $table->json('transports')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('two_factor_methods');
    }
};
