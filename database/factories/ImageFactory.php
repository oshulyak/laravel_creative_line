<?php

namespace Database\Factories;

use App\Models\Image;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Image>
 */
class ImageFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'img_path' => 'images/'.fake()->uuid().'.jpg',
            // По умолчанию изображение принадлежит посту; в сидерах imageable
            // переопределяется через ->for(..., 'imageable') на категорию/профиль/комментарий.
            'imageable_id' => Post::factory(),
            'imageable_type' => Post::class,
        ];
    }
}
