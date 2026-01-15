<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Admin - puede ver y usar ambos números
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL')],
            [
                'name' => env('ADMIN_NAME', 'Administrador'),
                'password' => Hash::make(env('ADMIN_PASSWORD')),
                'role' => 'admin',
                'phone_number_id' => null,
                'phone_number_name' => null,
            ]
        );

        // Usuario ATC - solo número 212
        User::updateOrCreate(
            ['email' => env('ATC_EMAIL')],
            [
                'name' => env('ATC_NAME', 'Usuario ATC'),
                'password' => Hash::make(env('ATC_PASSWORD')),
                'role' => 'user',
                'phone_number_id' => env('ATC_PHONE_NUMBER_ID'),
                'phone_number_name' => env('ATC_PHONE_NUMBER_NAME'),
            ]
        );

        // Usuario Comunidad - solo número 154
        User::updateOrCreate(
            ['email' => env('COMUNIDAD_EMAIL')],
            [
                'name' => env('COMUNIDAD_NAME', 'Usuario Comunidad'),
                'password' => Hash::make(env('COMUNIDAD_PASSWORD')),
                'role' => 'user',
                'phone_number_id' => env('COMUNIDAD_PHONE_NUMBER_ID'),
                'phone_number_name' => env('COMUNIDAD_PHONE_NUMBER_NAME'),
            ]
        );
    }
}
