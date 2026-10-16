<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Peuple `organisations` depuis chaque ligne de `sis` existante, en
     * préservant l'id (organisations.id === sis.id) et api_key → org_key.
     * Requête brute (pas le modèle Eloquent `Sis`) pour ne pas redéclencher
     * `SisObserver`, déjà responsable des SIS créés/modifiés après ce
     * backfill.
     */
    public function up(): void
    {
        $domaineId = DB::table('domaines')->where('code', 'sis')->value('id');

        DB::table('sis')->orderBy('id')->each(function (object $sis) use ($domaineId) {
            DB::table('organisations')->updateOrInsert(
                ['id' => $sis->id],
                [
                    'domaine_id' => $domaineId,
                    'nom' => $sis->nom,
                    'org_key' => $sis->api_key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Backfill de données, non réversible proprement (on ne sait pas quelles
     * lignes de organisations préexistaient) ; la table elle-même se retire
     * via la migration de création (2026_10_16_000002).
     */
    public function down(): void
    {
        // Intentionnellement vide — voir commentaire ci-dessus.
    }
};
