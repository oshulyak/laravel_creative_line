<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class CommentSeeder extends Seeder {
    /**
     * Run the database seeds.
     */
    public function run(): void {
        $profiles = Profile::all();

        // Для каждого поста создаём 1-4 комментария от случайных существующих профилей.
        Post::all()->each(function (Post $post) use ($profiles) {
            Comment::factory(fake()->numberBetween(1, 4))
                ->recycle($profiles)   // автор комментария (author_id) — существующий профиль
                ->for($post)           // комментарий принадлежит этому посту (post_id)
                ->create();
        });
    }
}
