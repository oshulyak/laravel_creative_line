<?php

namespace Database\Factories;

use App\Models\Chat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Участников фабрика не создаёт: в тестах их добавляют через связь,
 * $chat->profiles()->attach([...]), — так видно, кто именно в чате.
 *
 * @extends Factory<Chat>
 */
class ChatFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            // По умолчанию — диалог: своего названия у него нет.
            'title' => null,
        ];
    }
}
