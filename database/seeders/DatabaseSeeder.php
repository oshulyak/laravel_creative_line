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
        //    firstOrCreate делает сидер идемпотентным: повторный db:seed (даже без migrate:fresh)
        //    не создаст дубль и не упадёт на уникальном email. Остальные поля (password 'password',
        //    email_verified_at, remember_token) берём из UserFactory через raw().
        //    Важно: email фиксируем и в raw() — иначе фабрика сгенерирует случайный и перебьёт поиск.
        $email = 'test@example.com';

        $testUser = User::firstOrCreate(
            ['email' => $email],
            User::factory()->raw([
                'name' => 'Test User',
                'email' => $email,
            ]),
        );

        // Профиль тоже через идемпотентный firstOrCreate; связь hasOne сама проставит user_id,
        // поэтому в атрибутах поиска передаём пустой массив (ищем «любой профиль этого пользователя»).
        $testUser->profile()->firstOrCreate([], [
            'nickname' => 'test_user',
            'first_name' => 'Test',
            'second_name' => 'User',
            'gender' => 'male',
            'city' => 'Moscow',
        ]);

        // Вариант 2 (ленивый), как альтернатива созданию пользователя выше:
        //   фабрика отрабатывает только если строки ещё нет — firstWhere вернёт
        //   существующего пользователя, иначе создаст его фабрикой. Минус — два
        //   отдельных шага без атомарности (для сидера некритично).
        //   Профиль создаётся тем же блоком firstOrCreate, что и выше.
        //
        // $testUser = User::firstWhere('email', $email)
        //     ?? User::factory()->create([
        //         'name' => 'Test User',
        //         'email' => $email,
        //     ]);

        // 2. Случайные пользователи, у каждого — свой профиль (hasOne) через магический ->hasProfile().
        User::factory(10)->hasProfile()->create();

        // 3. Справочные данные и контент. Порядок важен и идёт по зависимостям:
        //    посты ссылаются на профили и категории; комментарии — на посты;
        //    изображения и файлы цепляются к постам и комментариям (Imageable/Fileable);
        //    лайки ставятся в т.ч. на изображения, поэтому LikeSeeder идёт последним.
        $this->call([
            CategorySeeder::class,
            TagSeeder::class,
            PostSeeder::class,
            CommentSeeder::class,
            ImageSeeder::class,
            FileSeeder::class,
            LikeSeeder::class,
        ]);
    }
}
