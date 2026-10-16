<?php

namespace Database\Seeders;

use App\Models\Domaine;
use Illuminate\Database\Seeder;

class DomaineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // self_registration_enabled est fixé ici en plus de la migration
        // 2026_10_16_000006 : sur `migrate:fresh --seed`, toutes les
        // migrations tournent avant tous les seeders, donc le backfill de
        // la migration ne voit aucune ligne `domaines` à mettre à jour (voir
        // le même piège déjà rencontré pour `permissions.domaine_id`).
        Domaine::updateOrCreate(['code' => 'sis'], ['nom' => 'GestSIS', 'self_registration_enabled' => true]);
        Domaine::updateOrCreate(['code' => 'eca'], ['nom' => 'GestECA']);
    }
}
