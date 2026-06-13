<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('my:test')]
#[Description('Демонстрирует through-связи моделей через dd() (урок 6).')]
class MyTest extends Command {
    /**
     * Достаёт из базы записи, у которых есть связанные данные, и выводит
     * результат through-связей через dd(). Данные предполагаются заполненными
     * сидерами (`php artisan db:seed`).
     */
    public function handle(): void {
        $category = Category::has('posts')->firstOrFail();
        $user = User::whereHas('profile.posts')->firstOrFail();
        $profile = Profile::has('posts')->firstOrFail();

        dd(
            // Category → Post → Comment: id комментариев ко всем постам категории.
            "Category::comments() — category id={$category->id}",
            $category->comments->pluck('id')->all(),

            // User → Profile → Post: id постов пользователя через его профиль.
            "User::posts() — user id={$user->id}",
            $user->posts->pluck('id')->all(),

            // User → Profile → Comment: id комментариев, написанных пользователем.
            "User::comments() — user id={$user->id}",
            $user->comments->pluck('id')->all(),

            // Profile → Post → Comment: id комментариев к постам профиля (от кого угодно).
            "Profile::postComments() — profile id={$profile->id}",
            $profile->postComments->pluck('id')->all(),
        );
    }
}
