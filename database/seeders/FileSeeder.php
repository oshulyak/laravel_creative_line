<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\File;
use App\Models\Image;
use App\Models\Post;
use Illuminate\Database\Seeder;

class FileSeeder extends Seeder {
    /**
     * Run the database seeds.
     */
    public function run(): void {
        // Fileable — одно ко многим: у части постов и комментариев есть прикреплённые файлы.
        Post::all()->each(function (Post $post) {
            File::factory(fake()->numberBetween(0, 2))
                ->for($post, 'fileable')
                ->create();
        });

        Comment::all()->each(function (Comment $comment) {
            if (fake()->boolean(25)) {
                File::factory()->for($comment, 'fileable')->create();
            }
        });

        // Fileable — одно к одному: у части изображений есть файл-оригинал.
        Image::all()->each(function (Image $image) {
            if (fake()->boolean(50)) {
                File::factory()->for($image, 'fileable')->create();
            }
        });
    }
}
