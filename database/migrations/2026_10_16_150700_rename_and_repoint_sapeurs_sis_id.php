<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        // Garde-fou avant un repointage de FK irréversible (côté data) : vérifie que
        // l'invariant organisations.id === sis.id (sur lequel repose ce repointage,
        // cf. commentaire de classe) tient réellement, plutôt que de le supposer.
        $mismatches = DB::table('sis')
            ->leftJoin('organisations', 'organisations.id', '=', 'sis.id')
            ->where(function ($query) {
                $query->whereNull('organisations.id')
                    ->orWhereColumn('organisations.org_key', '!=', 'sis.api_key');
            })
            ->count();

        if ($mismatches > 0) {
            throw new \RuntimeException(
                "Invariant organisations.id === sis.id violé pour {$mismatches} ligne(s) sis : ".
                'repointage de sapeurs.sis_id annulé avant de perdre la correspondance.'
            );
        }

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
