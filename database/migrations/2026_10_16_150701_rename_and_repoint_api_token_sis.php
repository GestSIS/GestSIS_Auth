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
        // Le FK est droppé/renommé AVANT le renommage de table : MySQL ne
        // renomme pas le nom de la contrainte avec la table (elle reste
        // "api_token_sis_sis_id_foreign"), donc dropForeign(['sis_id'])
        // devinerait un nom erroné ("api_token_org_sis_id_foreign") s'il
        // tournait après Schema::rename().
        Schema::table('api_token_sis', function (Blueprint $table) {
            $table->dropForeign(['sis_id']);
            $table->renameColumn('sis_id', 'organisation_id');
        });

        Schema::rename('api_token_sis', 'api_token_org');

        Schema::table('api_token_org', function (Blueprint $table) {
            $table->foreign('organisation_id')->references('id')->on('organisations')->onDelete('cascade');
        });
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
