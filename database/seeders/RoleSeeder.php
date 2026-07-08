<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder {
    /**
     * Создаёт базовые роли. firstOrCreate делает сидер идемпотентным —
     * повторный запуск не создаёт дубль роли.
     */
    public function run(): void {
        Role::firstOrCreate(['title' => 'admin']);
    }
}
