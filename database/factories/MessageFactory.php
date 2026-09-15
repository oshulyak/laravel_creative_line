<?php

namespace Database\Factories;

use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            // Чат и автор по умолчанию создаются свои. В тестах их задают
            // явно: ->for($chat)->for($profile, 'author').
            //
            // chat_id нет в $fillable модели, но фабрике это не мешает:
            // при создании записей она отключает защиту массового заполнения.
            'chat_id' => Chat::factory(),
            'author_id' => Profile::factory(),
            'content' => fake()->sentence(),
        ];
    }
}
