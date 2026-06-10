<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class LikeSeeder extends Seeder {
    /**
     * Run the database seeds.
     */
    public function run(): void {
        $profiles = Profile::all();

        // Лайк — запись в pivot-таблице post_profile_likes (связь многие-ко-многим профиль <-> пост).
        Post::all()->each(function (Post $post) use ($profiles) {
            $likers = $profiles->random(
                fake()->numberBetween(0, min(5, $profiles->count()))
            );

            // attach принимает массив id; уникальная пара (post_id, profile_id) защищает от дублей.
            $post->likedByProfiles()->attach($likers->pluck('id'));
        });
    }
}
