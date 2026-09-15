<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\Theme;
use App\Models\ThemeMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThemeMessage>
 */
class ThemeMessageFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            // Тема и автор по умолчанию создаются свои. В тестах их задают
            // явно: ->for($theme)->for($profile, 'author').
            'theme_id' => Theme::factory(),
            'author_id' => Profile::factory(),
            'content' => fake()->sentence(),
        ];
    }
}
