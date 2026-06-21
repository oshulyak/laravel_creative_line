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
            // По умолчанию комментарий относится к посту (commentable = Post).
            // В сидерах/тестах commentable переопределяется через ->for(..., 'commentable'):
            // для ответа в ветке туда передаётся другой Comment.
            'commentable_id' => Post::factory(),
            'commentable_type' => Post::class,
            'author_id' => Profile::factory(),
            'content' => fake()->paragraph(),
            'status' => $status,
            'published_at' => $status === Comment::STATUS_PUBLISHED
                ? fake()->dateTimeBetween('-1 month', 'now')
                : null,
        ];
    }
}
