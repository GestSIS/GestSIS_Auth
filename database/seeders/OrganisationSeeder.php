<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrganisationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $domaineId = DB::table('domaines')->where('code', 'sis')->value('id');

        DB::table('organisations')->insert([
            ['id' => 1, 'domaine_id' => $domaineId, 'nom' => 'SIS Haute-Sorne', 'org_key' => 'hs', 'abreviation' => 'SIS-HS', 'mobile' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'domaine_id' => $domaineId, 'nom' => 'SIS Test', 'org_key' => 'test', 'abreviation' => 'SIS-Test', 'mobile' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
