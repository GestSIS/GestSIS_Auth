<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $elements = array(
            array('id' => 1, 'nom' => 'admin', 'description' => 'Temp rôle for tests', 'sis_id' => 1),
        );

        foreach ($elements as $element) {
            DB::table('roles')->insert($element);
        }

        $elements = array(
            array('permission_id' => 1, 'role_id' => 1),
            array('permission_id' => 2, 'role_id' => 1),
            array('permission_id' => 3, 'role_id' => 1),
            array('permission_id' => 4, 'role_id' => 1),
            array('permission_id' => 5, 'role_id' => 1),
            array('permission_id' => 6, 'role_id' => 1),
            array('permission_id' => 7, 'role_id' => 1),
            array('permission_id' => 8, 'role_id' => 1),
            array('permission_id' => 9, 'role_id' => 1),
            array('permission_id' => 10, 'role_id' => 1),
            array('permission_id' => 11, 'role_id' => 1),
            array('permission_id' => 12, 'role_id' => 1),
            array('permission_id' => 13, 'role_id' => 1),
            array('permission_id' => 14, 'role_id' => 1),
            array('permission_id' => 15, 'role_id' => 1),
            array('permission_id' => 16, 'role_id' => 1),
            array('permission_id' => 17, 'role_id' => 1),
        );

        foreach ($elements as $element) {
            DB::table('permission_roles')->insert($element);
        }
    }
}
