<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        $status = fake()->randomElement(array_keys(Post::getStatuses()));

        return [
            // Внешние ключи по умолчанию создают связанные записи; в сидере переопределяем их
            // на существующие профили/категории через recycle(), чтобы не плодить лишние строки.
            'author_id' => Profile::factory(),
            'category_id' => Category::factory(),
            'title' => fake()->unique()->sentence(),
            'content' => fake()->paragraphs(3, true),
            'img_path' => null,
            'status' => $status,
            // Дата публикации заполняется только у опубликованных постов — у тех, что на модерации, её нет.
            'published_at' => $status === Post::STATUS_PUBLISHED
                ? fake()->dateTimeBetween('-1 month', 'now')
                : null,
        ];
    }
}
