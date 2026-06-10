<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        $status = fake()->randomElement(array_keys(Comment::getStatuses()));

        return [
            'post_id' => Post::factory(),
            'author_id' => Profile::factory(),
            // По умолчанию комментарий верхнего уровня; ответы (дерево) можно задать состоянием отдельно.
            'parent_id' => null,
            'content' => fake()->paragraph(),
            'status' => $status,
            'published_at' => $status === Comment::STATUS_PUBLISHED
                ? fake()->dateTimeBetween('-1 month', 'now')
                : null,
        ];
    }
}
