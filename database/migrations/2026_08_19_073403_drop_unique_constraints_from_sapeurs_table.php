<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Plusieurs lignes historiques (coupées) peuvent désormais coexister pour
     * un même sapeur ou un même utilisateur dans un SIS : seule une ligne
     * *active* (deactivated_at null) doit rester unique par (sapeur_id, sis_id)
     * et par (user_id, sis_id), mais cette contrainte n'est plus imposée au
     * niveau base (MySQL ne supporte pas les index uniques partiels) — elle
     * est garantie par l'application (SyncSapeurUserMappings, ProcessAccountDeactivation).
     *
     * Vérifie l'existence de chaque index avant de le supprimer : selon
     * l'environnement, seule la contrainte (user_id, sis_id) a pu être
     * réellement créée à l'origine.
     */
    public function up(): void
    {
        $existing = collect(Schema::getIndexes('sapeurs'))->pluck('name');

        // Les index uniques existants couvrent aussi la contrainte de clé étrangère
        // sur user_id : MySQL refuse de les supprimer tant qu'un autre index ne les
        // remplace pas. On crée donc les nouveaux index simples en premier.
        Schema::table('sapeurs', function (Blueprint $table) use ($existing) {
            if (!$existing->contains('sapeurs_sapeur_id_sis_id_index')) {
                $table->index(['sapeur_id', 'sis_id']);
            }
            if (!$existing->contains('sapeurs_user_id_sis_id_index')) {
                $table->index(['user_id', 'sis_id']);
            }
        });

        Schema::table('sapeurs', function (Blueprint $table) use ($existing) {
            if ($existing->contains('sapeurs_sapeur_id_sis_id_unique')) {
                $table->dropUnique('sapeurs_sapeur_id_sis_id_unique');
            }
            if ($existing->contains('sapeurs_user_id_sis_id_unique')) {
                $table->dropUnique('sapeurs_user_id_sis_id_unique');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Même contrainte d'ordre que up() : créer les uniques avant de supprimer
        // les index simples qui soutiennent la clé étrangère sur user_id.
        Schema::table('sapeurs', function (Blueprint $table) {
            $table->unique(['sapeur_id', 'sis_id']);
            $table->unique(['user_id', 'sis_id']);
        });

        Schema::table('sapeurs', function (Blueprint $table) {
            $table->dropIndex(['sapeur_id', 'sis_id']);
            $table->dropIndex(['user_id', 'sis_id']);
        });
    }
};
