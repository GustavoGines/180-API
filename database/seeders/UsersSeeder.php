<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        // Sugerencia: podés pasar las contraseñas por ENV si querés
        $users = [
            [
                'name'  => 'Gustavo',
                'email' => 'ginesparker95@gmail.com',
                'password' => env('SEED_PWD_GUSTAVO', 'Gusty1996'),
                'role'  => 'admin',
            ]
        ];

        foreach ($users as $u) {
            $user = User::firstOrNew(['email' => $u['email']]);

            // siempre actualizamos nombre y rol
            $user->name = $u['name'];
            $user->role = $u['role'];

            // si es nuevo o la password en texto cambió, la re-hasheamos
            if (!$user->exists || !Hash::check($u['password'], $user->password ?? '')) {
                $user->password = Hash::make($u['password']);
            }

            $user->save();
        }

        // (Opcional) mostrar por consola
        $this->command->info('Usuarios sembrados/actualizados: '.count($users));
        $this->command->warn('Recordá cambiar las passwords por ENV en producción.');
    }
}
