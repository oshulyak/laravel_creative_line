<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Profile;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Theme>
 */
class ThemeFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            // Группа и автор по умолчанию создаются свои. В тестах их задают
            // явно: ->for($group)->for($profile, 'author').
            //
            // group_id нет в $fillable модели, но фабрике это не мешает:
            // при создании записей она отключает защиту массового заполнения.
            'group_id' => Group::factory(),
            'author_id' => Profile::factory(),
            'title' => fake()->sentence(3),
        ];
    }
}
