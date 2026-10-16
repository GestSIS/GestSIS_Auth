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
     * domaine_id nullable : NULL = permission sans domaine (garde du code Auth
     * générique partagé entre domaines, ex. utilisateur.config/lecture/modification).
     * Toutes les autres permissions existantes sont backfillées sur le domaine sis.
     */
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->foreignId('domaine_id')->nullable()->after('tri')->constrained('domaines');
        });

        $sisDomaineId = DB::table('domaines')->where('code', 'sis')->value('id');

        DB::table('permissions')
            ->whereNotIn('api_key', ['utilisateur.config', 'utilisateur.lecture', 'utilisateur.modification'])
            ->update(['domaine_id' => $sisDomaineId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('domaine_id');
        });
    }
};
