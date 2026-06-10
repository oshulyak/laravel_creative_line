<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder {
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void {
        // 1. Фиксированный пользователь с известным логином — удобно входить вручную при разработке.
        //    Пароль задаёт UserFactory ('password'), профиль создаём через связь hasOne.
        $testUser = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $testUser->profile()->create([
            'nickname' => 'test_user',
            'first_name' => 'Test',
            'second_name' => 'User',
            'gender' => 'male',
            'city' => 'Moscow',
        ]);

        // 2. Случайные пользователи, у каждого — свой профиль (hasOne) через магический ->hasProfile().
        User::factory(10)->hasProfile()->create();

        // 3. Справочные данные и контент. Порядок важен: посты ссылаются на профили и категории,
        //    а лайки и комментарии — на уже существующие посты и профили.
        $this->call([
            CategorySeeder::class,
            TagSeeder::class,
            PostSeeder::class,
            LikeSeeder::class,
            CommentSeeder::class,
        ]);
    }
}
