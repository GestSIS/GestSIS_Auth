<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * organisations.id === sis.id pour tout SIS existant (SisObserver, Task 2) :
     * aucune transformation de données nécessaire, seul le renommage de
     * colonne/FK. Deux Schema::table séparés car MySQL ne permet pas de
     * renommer et re-contraindre une colonne dans le même ALTER TABLE de
     * façon fiable via Laravel.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['sis_id']);
            $table->renameColumn('sis_id', 'organisation_id');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->foreign('organisation_id')->references('id')->on('organisations')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['organisation_id']);
            $table->renameColumn('organisation_id', 'sis_id');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->foreign('sis_id')->references('id')->on('sis')->onDelete('cascade');
        });
    }
};
