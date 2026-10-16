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

        // Des lignes historiques de `sapeurs` portent un `created_at`/`updated_at`
        // invalide ('0000-00-00 00:00:00'), toléré en lecture mais rejeté -- sous
        // sql_mode strict -- dès que ce littéral doit être converti en DATETIME
        // pour comparaison, y compris dans le WHERE de la requête qui l'assainit.
        // sql_mode assoupli pour cette seule opération, restauré juste après.
        $originalSqlMode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        DB::statement("SET SESSION sql_mode = ''");
        DB::statement("UPDATE sapeurs SET created_at = CASE WHEN updated_at != '0000-00-00 00:00:00' THEN updated_at ELSE NOW() END WHERE created_at = '0000-00-00 00:00:00'");
        DB::statement("UPDATE sapeurs SET updated_at = CASE WHEN created_at != '0000-00-00 00:00:00' THEN created_at ELSE NOW() END WHERE updated_at = '0000-00-00 00:00:00'");
        DB::statement('SET SESSION sql_mode = ' . DB::getPdo()->quote($originalSqlMode));

        // Idempotent : une exécution précédente peut avoir renommé la colonne puis
        // échoué avant d'ajouter la FK (DDL MySQL non transactionnel -- la colonne
        // reste renommée même si la migration entière est relancée depuis le début
        // au prochain déploiement).
        if (Schema::hasColumn('sapeurs', 'sis_id') && !Schema::hasColumn('sapeurs', 'organisation_id')) {
            Schema::table('sapeurs', function (Blueprint $table) {
                $table->dropForeign(['sis_id']);
                $table->renameColumn('sis_id', 'organisation_id');
            });
        }

        if (!$this->foreignKeyExists('sapeurs', 'sapeurs_organisation_id_foreign')) {
            Schema::table('sapeurs', function (Blueprint $table) {
                $table->foreign('organisation_id')->references('id')->on('organisations')->onDelete('cascade');
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraintName)
            ->exists();
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
