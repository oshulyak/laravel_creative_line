<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Image;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

class LikeSeeder extends Seeder {
    /**
     * Run the database seeds.
     */
    public function run(): void {
        $profiles = Profile::all();

        // Лайк — запись в полиморфном pivot likeables (Likeable: многие ко многим
        // профиль <-> пост/комментарий/изображение). Одна и та же логика «случайные
        // профили лайкают запись» применяется ко всем трём типам лайкаемых сущностей.
        $like = function (Collection $likeables) use ($profiles): void {
            $likeables->each(function ($likeable) use ($profiles): void {
                $likers = $profiles->random(
                    fake()->numberBetween(0, min(5, $profiles->count()))
                );

                // attach у morphToMany сам проставит likeable_type/likeable_id;
                // уникальный индекс (profile_id, likeable_type, likeable_id) защищает от дублей.
                $likeable->likedByProfiles()->attach($likers->pluck('id'));
            });
        };

        $like(Post::all());
        $like(Comment::all());
        $like(Image::all());
    }
}
