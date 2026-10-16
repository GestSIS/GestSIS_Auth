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
     * domaine_id nullable au niveau schéma, comme permissions.domaine_id : si
     * `domaines` est encore vide à cet instant (migrate:fresh --seed, où toutes
     * les migrations tournent avant tous les seeders), le backfill ci-dessous
     * ne voit rien à faire et la table reste vide — TwoFactorPolicySeeder
     * recrée les deux lignes canoniques juste après.
     */
    public function up(): void
    {
        $previous = DB::table('two_factor_policies')->first();

        Schema::table('two_factor_policies', function (Blueprint $table) {
            $table->foreignId('domaine_id')->nullable()->after('id')->constrained();
        });

        $sisDomaineId = DB::table('domaines')->where('code', 'sis')->value('id');
        $ecaDomaineId = DB::table('domaines')->where('code', 'eca')->value('id');

        if ($previous !== null) {
            // Ligne globale unique préexistante : devient la politique `sis`,
            // avec le même enforced_at (préserve le comportement actuel).
            DB::table('two_factor_policies')->where('id', $previous->id)->update(['domaine_id' => $sisDomaineId]);
        } elseif ($sisDomaineId !== null) {
            DB::table('two_factor_policies')->insert([
                'domaine_id' => $sisDomaineId,
                'enforced_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($ecaDomaineId !== null) {
            // enforced_at dans le passé : le 2FA est obligatoire dès la
            // création du domaine eca, aucun compte eca n'existant encore.
            DB::table('two_factor_policies')->insert([
                'domaine_id' => $ecaDomaineId,
                'enforced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('two_factor_policies', function (Blueprint $table) {
            $table->unique('domaine_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('two_factor_policies', function (Blueprint $table) {
            $table->dropUnique(['domaine_id']);
            $table->dropConstrainedForeignId('domaine_id');
        });
    }
};
