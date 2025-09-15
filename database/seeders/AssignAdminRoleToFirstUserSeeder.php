<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AssignAdminRoleToFirstUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin_role_id = DB::table('roles')->where('name', 'admin')->value('id');
        $first_user_id = DB::table('users')->orderBy('id')->value('id');

        if ($admin_role_id && $first_user_id) {
            $exists = DB::table('roleables')
                ->where('role_id', $admin_role_id)
                ->where('roleable_type', 'App\\Models\\User')
                ->where('roleable_id', $first_user_id)
                ->exists();

            if (!$exists) {
                DB::table('roleables')->insert([
                    'role_id' => $admin_role_id,
                    'roleable_type' => 'App\\Models\\User',
                    'roleable_id' => $first_user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
