<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class CommentSeeder extends Seeder {
    /**
     * Run the database seeds.
     */
    public function run(): void {
        $profiles = Profile::all();
        $tags = Tag::all();

        // Для каждого поста создаём 1-4 комментария от случайных профилей.
        Post::all()->each(function (Post $post) use ($profiles, $tags) {
            $comments = Comment::factory(fake()->numberBetween(1, 4))
                ->recycle($profiles)       // автор комментария (author_id)
                ->for($post, 'commentable') // commentable = пост (Commentable)
                ->create();

            $comments->each(function (Comment $comment) use ($profiles, $tags) {
                // Часть комментариев тегируем (Taggable: многие ко многим).
                if (fake()->boolean(40)) {
                    $comment->tags()->attach(
                        $tags->random(fake()->numberBetween(1, 2))->pluck('id')
                    );
                }

                // И к части пишем 1-2 ответа: commentable = сам комментарий
                // (та же связь Commentable, но родитель уже не пост, а комментарий).
                if (fake()->boolean(35)) {
                    Comment::factory(fake()->numberBetween(1, 2))
                        ->recycle($profiles)
                        ->for($comment, 'commentable')
                        ->create();
                }
            });
        });
    }
}
