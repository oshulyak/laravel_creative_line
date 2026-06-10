<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class PostSeeder extends Seeder {
    /**
     * Run the database seeds.
     */
    public function run(): void {
        // Берём уже созданные профили и категории, чтобы посты ссылались на реальные записи.
        $profiles = Profile::all();
        $categories = Category::all();
        $tags = Tag::all();

        // recycle() заставляет фабрику переиспользовать существующие профили/категории
        // вместо создания новых на каждый пост (иначе фабричные значения author_id/category_id породили бы лишние строки).
        Post::factory(20)
            ->recycle($profiles)
            ->recycle($categories)
            ->create()
            ->each(function (Post $post) use ($tags) {
                // Привязываем 1-3 случайных тега через pivot-таблицу post_tag (связь многие-ко-многим).
                $post->tags()->attach(
                    $tags->random(fake()->numberBetween(1, 3))->pluck('id')
                );
            });
    }
}
