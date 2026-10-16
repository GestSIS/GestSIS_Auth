<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SisSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('sis')->insert([
            // Permissions de base
            ['id' => 1, 'nom' => 'SIS Haute-Sorne', 'abreviation' => 'SIS-HS', 'api_key' => 'hs', 'mobile' => true],
            ['id' => 2, 'nom' => 'SIS Test', 'abreviation' => 'SIS-Test', 'api_key' => 'test', 'mobile' => true],
        ]);

        // Insertion en DB::table brute (pas le modèle Eloquent Sis) : SisObserver ne se
        // déclenche pas, donc on reproduit son effet ici pour que organisations reste
        // synchronisé avec les ids explicites ci-dessus.
        $domaineId = DB::table('domaines')->where('code', 'sis')->value('id');
        DB::table('organisations')->insert([
            ['id' => 1, 'domaine_id' => $domaineId, 'nom' => 'SIS Haute-Sorne', 'org_key' => 'hs', 'abreviation' => 'SIS-HS', 'mobile' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'domaine_id' => $domaineId, 'nom' => 'SIS Test', 'org_key' => 'test', 'abreviation' => 'SIS-Test', 'mobile' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
