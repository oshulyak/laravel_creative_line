<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('my:test {action=show : Действие: create — создать данные, show — показать связи через dd (по умолчанию)}')]
#[Description('Создаёт тестовые данные и демонстрирует связи моделей (урок 4).')]
class MyTest extends Command {
    /**
     * Execute the console command.
     *
     * По аргументу команды выбираем действие: create — наполнить базу тестовыми
     * данными, show — вывести связанные данные через dd().
     */
    public function handle(): void {
        match ($this->argument('action')) {
            'create' => $this->create(),
            'show' => $this->show(),
            default => $this->error('Неизвестное действие. Допустимо: create или show.'),
        };
    }

    /**
     * Наполняет базу тестовыми данными «вручную» через create()/attach().
     * Рассчитана на чистую базу (запускать после `php artisan migrate:fresh`).
     */
    private function create(): void {
        // 1. Роли (для связи многие-ко-многим с пользователями).
        $adminRole = Role::create(['title' => 'admin']);
        $userRole = Role::create(['title' => 'user']);

        // 2. Категории публикаций (одна категория — много постов).
        $newsCategory = Category::create(['title' => 'Новости']);
        $techCategory = Category::create(['title' => 'Технологии']);

        // 3. Теги (многие-ко-многим с публикациями через post_tag).
        $laravelTag = Tag::create(['title' => 'laravel']);
        $phpTag = Tag::create(['title' => 'php']);
        $eloquentTag = Tag::create(['title' => 'eloquent']);

        // 4. Пользователи + их профили (один-к-одному) + роли.
        $alice = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'phone' => '+10000000001',
            'password' => 'password',
        ]);
        $alice->profile()->create([
            'nickname' => 'alice',
            'first_name' => 'Alice',
            'second_name' => 'Smith',
            'city' => 'Москва',
        ]);
        $alice->roles()->attach([$adminRole->id, $userRole->id]);

        $bob = User::create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'phone' => '+10000000002',
            'password' => 'password',
        ]);
        $bob->profile()->create([
            'nickname' => 'bob',
            'first_name' => 'Bob',
            'second_name' => 'Brown',
            'city' => 'Казань',
        ]);
        $bob->roles()->attach($userRole->id);

        // 5. Публикации создаём через связь автора: posts()->create() сам подставит
        //    author_id. category_id передаём вручную — это другая связь (belongsTo).
        $firstPost = $alice->profile->posts()->create([
            'category_id' => $techCategory->id,
            'title' => 'Знакомство с Eloquent',
            'content' => 'Связи в Laravel: hasOne, hasMany, belongsTo, belongsToMany.',
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $secondPost = $bob->profile->posts()->create([
            'category_id' => $newsCategory->id,
            'title' => 'Релиз новой версии',
            'content' => 'Сегодня вышло обновление нашего проекта.',
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        // 6. Теги публикаций (заполняем pivot post_tag через attach).
        $firstPost->tags()->attach([$laravelTag->id, $eloquentTag->id]);
        $secondPost->tags()->attach($phpTag->id);

        // 7. Изображение публикации через связь images() поста (авто-post_id).
        $firstPost->images()->create([
            'img_path' => 'images/eloquent-cover.png',
        ]);

        // 8. Лайки (pivot post_profile_likes): Bob лайкнул пост Alice и наоборот.
        $firstPost->likedByProfiles()->attach($bob->profile->id);
        $secondPost->likedByProfiles()->attach([$alice->profile->id, $bob->profile->id]);

        // 9. Корневой комментарий через связь comments() поста (авто-post_id).
        //    author_id передаём вручную — это отдельная связь (автор-профиль).
        $comment = $firstPost->comments()->create([
            'author_id' => $bob->profile->id,
            'content' => 'Отличная статья, спасибо!',
            'status' => Comment::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        // Ответ создаём через самосвязь replies() родителя (авто-parent_id).
        //    post_id и author_id задаём явно — replies() заполняет только parent_id.
        $comment->replies()->create([
            'post_id' => $firstPost->id,
            'author_id' => $alice->profile->id,
            'content' => 'Рада, что пригодилось :)',
            'status' => Comment::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->info('Тестовые данные созданы. Запустите `my:test show` для демонстрации связей.');
    }

    /**
     * Выводит связанные данные через dd(). Нужные записи достаём из БД по
     * известным значениям, созданным в create().
     */
    private function show(): void {
        $alice = User::where('email', 'alice@example.com')->firstOrFail();
        $firstPost = Post::where('title', 'Знакомство с Eloquent')->firstOrFail();
        $secondPost = Post::where('title', 'Релиз новой версии')->firstOrFail();

        // Демонстрация связей через dd() с жадной загрузкой (eager loading),
        // чтобы избежать проблемы N+1 при обращении к отношениям.
        dd(
            // hasOne / belongsTo: пользователь -> профиль, профиль -> пользователь.
            $alice->load('profile')->profile->nickname,
            $alice->profile->load('user')->user->email,
            // belongsToMany: роли пользователя.
            $alice->load('roles')->roles->pluck('title'),
            // hasMany: посты профиля.
            $alice->profile->load('posts')->posts->pluck('title'),
            // belongsTo: автор и категория поста.
            $firstPost->load('author', 'category')->author->nickname,
            $firstPost->category->title,
            // belongsToMany: теги поста и профили, лайкнувшие пост.
            $firstPost->load('tags', 'likedByProfiles')->tags->pluck('title'),
            $secondPost->load('likedByProfiles')->likedByProfiles->pluck('nickname'),
            // hasMany + самосвязь: комментарии поста с ответами.
            $firstPost->load('comments.replies', 'images')->comments->toArray(),
        );
    }
}
