<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Image;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class ImageSeeder extends Seeder {
    /**
     * Run the database seeds.
     */
    public function run(): void {
        // Imageable — одно к одному: по одному изображению на категорию и профиль.
        // ->for($model, 'imageable') проставляет imageable_type/imageable_id.
        Category::all()->each(function (Category $category) {
            Image::factory()->for($category, 'imageable')->create();
        });

        Profile::all()->each(function (Profile $profile) {
            Image::factory()->for($profile, 'imageable')->create();
        });

        // Imageable — одно ко многим: у части постов несколько изображений.
        Post::all()->each(function (Post $post) {
            Image::factory(fake()->numberBetween(0, 3))
                ->for($post, 'imageable')
                ->create();
        });

        // И у части комментариев тоже (одно ко многим).
        Comment::all()->each(function (Comment $comment) {
            if (fake()->boolean(30)) {
                Image::factory(fake()->numberBetween(1, 2))
                    ->for($comment, 'imageable')
                    ->create();
            }
        });
    }
}
