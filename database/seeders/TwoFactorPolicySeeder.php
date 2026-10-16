<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TwoFactorPolicySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Sur une installation neuve, la migration 2026_10_16_000008 (dont le
     * backfill des deux lignes `sis`/`eca`) s'exécute avant ce seeder, donc
     * avant que `domaines` ne contienne quoi que ce soit : on reproduit ici
     * le même backfill pour qu'une base fraîchement seedée reste cohérente
     * (même piège déjà rencontré pour `permissions.domaine_id`).
     *
     * @return void
     */
    public function run()
    {
        $sisDomaineId = DB::table('domaines')->where('code', 'sis')->value('id');
        $ecaDomaineId = DB::table('domaines')->where('code', 'eca')->value('id');

        if (DB::table('two_factor_policies')->where('domaine_id', $sisDomaineId)->doesntExist()) {
            DB::table('two_factor_policies')->insert([
                'domaine_id' => $sisDomaineId,
                'enforced_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::table('two_factor_policies')->where('domaine_id', $ecaDomaineId)->doesntExist()) {
            DB::table('two_factor_policies')->insert([
                'domaine_id' => $ecaDomaineId,
                'enforced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
