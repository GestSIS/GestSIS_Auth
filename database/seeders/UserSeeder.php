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
        $elements = array(
            array('id' => 1, 'name' => 'admin', 'admin' => true, 'email' => 'test@gmail.com', 'email_verified_at' => Carbon::yesterday(), 'password' => Hash::make('apptest')),
        );

        foreach ($elements as $element) {
            DB::table('users')->insert($element);
        }

        $elements = array(
            array('user_id' => 1, 'role_id' => 1),
            array('user_id' => 1, 'role_id' => 3),
        );

        foreach ($elements as $element) {
            DB::table('user_roles')->insert($element);
        }
    }
}
