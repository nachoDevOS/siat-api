<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Usuario administrador del panel. Cambiar la contrasena en produccion.
        User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            ['name' => 'Administrador', 'password' => bcrypt('password')],
        );

        // Casos de prueba del piloto (seccion 12.2).
        $this->call(CasosPruebaSeeder::class);
    }
}
