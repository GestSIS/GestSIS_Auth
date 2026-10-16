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
        Domaine::updateOrCreate(['code' => 'sis'], ['nom' => 'GestSIS']);
        Domaine::updateOrCreate(['code' => 'eca'], ['nom' => 'GestECA']);
    }
}
