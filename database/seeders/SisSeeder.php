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
        $elements = [
            // Permissions de base
            ['id' => 1, 'nom' => 'SIS Haute-Sorne', 'abreviation' => 'SIS-HS', 'api_key' => 'hs', 'mobile' => true],
            ['id' => 2, 'nom' => 'SIS Test', 'abreviation' => 'SIS-Test', 'api_key' => 'test', 'mobile' => true],
        ];

        foreach ($elements as $element) {
            DB::table('sis')->insert($element);
        }
    }
}
