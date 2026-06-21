<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'file_path' => 'files/'.fake()->uuid().'.'.fake()->fileExtension(),
            // По умолчанию файл принадлежит посту; в сидерах fileable
            // переопределяется через ->for(..., 'fileable') на комментарий/изображение.
            'fileable_id' => Post::factory(),
            'fileable_type' => Post::class,
        ];
    }
}
