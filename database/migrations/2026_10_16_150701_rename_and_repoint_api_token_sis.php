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
        // Le FK est droppé/renommé AVANT le renommage de table : MySQL ne
        // renomme pas le nom de la contrainte avec la table (elle reste
        // "api_token_sis_sis_id_foreign"), donc dropForeign(['sis_id'])
        // devinerait un nom erroné ("api_token_org_sis_id_foreign") s'il
        // tournait après Schema::rename().
        //
        // Idempotent (chaque étape testée avant d'être rejouée) : le DDL MySQL
        // n'est pas transactionnel, donc une migration précédente ayant échoué
        // en cours de route (cf. 2026_10_16_150700, touchée en déploiement réel
        // par des `created_at` historiques invalides sur `sapeurs`) peut avoir
        // laissé ce schéma à mi-chemin ; au prochain déploiement, la migration
        // entière est rejouée depuis le début.
        if (Schema::hasTable('api_token_sis') && Schema::hasColumn('api_token_sis', 'sis_id')) {
            Schema::table('api_token_sis', function (Blueprint $table) {
                $table->dropForeign(['sis_id']);
                $table->renameColumn('sis_id', 'organisation_id');
            });
        }

        if (Schema::hasTable('api_token_sis')) {
            Schema::rename('api_token_sis', 'api_token_org');
        }

        if (!$this->foreignKeyExists('api_token_org', 'api_token_org_organisation_id_foreign')) {
            Schema::table('api_token_org', function (Blueprint $table) {
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
        Schema::table('api_token_org', function (Blueprint $table) {
            $table->dropForeign(['organisation_id']);
            $table->renameColumn('organisation_id', 'sis_id');
        });

        Schema::rename('api_token_org', 'api_token_sis');

        Schema::table('api_token_sis', function (Blueprint $table) {
            $table->foreign('sis_id')->references('id')->on('sis')->onDelete('cascade');
        });
    }
};
