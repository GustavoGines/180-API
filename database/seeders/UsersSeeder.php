<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Gustavo', 'email' => 'ginesparker95@gmail.com', 'password' => 'Gusty1996'],
            ['name' => 'Lorena',  'email' => 'lobelcab@gmail.com',  'password' => 'Lorena1995'],
            ['name' => 'Jacky',    'email' => 'yacquelinemendoza75@gmail.com',    'password' => 'Gladys2025'],
            ['name' => 'Karen',  'email' => 'kacecicab@gmail.com',  'password' => 'Karen1996'],
        ];
     
        foreach ($users as $u) {
            \App\Models\User::updateOrCreate(
                ['email' => $u['email']],
                ['name' => $u['name'], 'password' => bcrypt($u['password'])]
            );
        }
        }
}
