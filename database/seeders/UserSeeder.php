<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'admin', 'admin' => true, 'email' => 'admin@gestsis.ch', 'email_verified_at' => Carbon::yesterday(), 'password' => Hash::make('apptest')],
            ['id' => 1, 'name' => 'admin', 'admin' => true, 'email' => 'user@gestsis.ch', 'email_verified_at' => Carbon::yesterday(), 'password' => Hash::make('apptest')],
        ]);

        DB::table('user_roles')->insert([
            ['user_id' => 1, 'role_id' => 1],
            ['user_id' => 1, 'role_id' => 3],
        ]);

        DB::table('sapeurs')->insert([
            ['user_id' => 1, 'sapeur_id' => 1, 'sis_id' => 1],
            ['user_id' => 1, 'sapeur_id' => 1, 'sis_id' => 1],
        ]);
    }
}
