<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * organisations.id === sis.id pour tout SIS existant (SisObserver) : aucune
     * transformation de données nécessaire, seul le renommage de colonne/FK.
     * Deux Schema::table séparés car MySQL ne permet pas de renommer et
     * re-contraindre une colonne dans le même ALTER TABLE de façon fiable via
     * Laravel (même pattern que 2026_10_16_000005_rename_roles_sis_id_to_organisation_id.php).
     */
    public function up(): void
    {
        Schema::table('sapeurs', function (Blueprint $table) {
            $table->dropForeign(['sis_id']);
            $table->renameColumn('sis_id', 'organisation_id');
        });

        Schema::table('sapeurs', function (Blueprint $table) {
            $table->foreign('organisation_id')->references('id')->on('organisations')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sapeurs', function (Blueprint $table) {
            $table->dropForeign(['organisation_id']);
            $table->renameColumn('organisation_id', 'sis_id');
        });

        Schema::table('sapeurs', function (Blueprint $table) {
            $table->foreign('sis_id')->references('id')->on('sis')->onDelete('cascade');
        });
    }
};
