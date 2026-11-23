<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les tokens d'inscription sont supprimés : un compte ne se crée plus
     * qu'avec un email correspondant à un sapeur/civil actif, et les rôles
     * s'attribuent via la gestion des utilisateurs.
     */
    public function up(): void
    {
        Schema::dropIfExists('register_token_roles');
        Schema::dropIfExists('register_tokens');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('register_tokens', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->string('token')->unique();
            $table->string('description')->default('');
            $table->date('validite');
        });

        Schema::create('register_token_roles', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->unsignedBigInteger('register_token_id');
            $table->foreign('register_token_id')->references('id')->on('register_tokens')->onDelete('cascade');

            $table->unsignedBigInteger('role_id');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');

            $table->unique(['register_token_id', 'role_id']);
        });
    }
};
