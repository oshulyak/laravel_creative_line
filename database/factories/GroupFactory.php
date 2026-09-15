<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Участников фабрика не создаёт: в тестах их добавляют через связь,
 * $group->subscribers()->attach(...), — как у ChatFactory.
 *
 * @extends Factory<Group>
 */
class GroupFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'title' => fake()->words(2, true),
            'description' => fake()->sentence(),
        ];
    }
}
