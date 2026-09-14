<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'profile_id' => Profile::factory(),
            'actor_id' => Profile::factory(),
            'body' => fake()->sentence(),
            // Источник по умолчанию — пост, хотя в жизни чаще встречается
            // комментарий. Причина в обсерверах: Comment::factory() внутри
            // подняла бы CommentObserver, и вместе с нужной строкой в базе
            // появилась бы ВТОРАЯ, созданная обсервером. Тест, считающий
            // уведомления, сломался бы на ровном месте.
            //
            // Post::factory() безопасен: PostObserver реагирует только на репосты
            // (parent_id заполнен), а обычный пост его не трогает.
            'notificationable_id' => Post::factory(),
            'notificationable_type' => Post::class,
            'read_at' => null,
        ];
    }
}
