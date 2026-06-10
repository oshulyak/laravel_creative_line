<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        $gender = fake()->randomElement(['male', 'female']);

        return [
            // Профиль принадлежит пользователю. Значение-фабрика создаёт нового User,
            // но при сидинге через User::factory()->hasProfile() Laravel подставит сюда id готового пользователя.
            'user_id' => User::factory(),
            'nickname' => fake()->unique()->userName(),
            'first_name' => $gender === 'male' ? fake()->firstNameMale() : fake()->firstNameFemale(),
            'second_name' => fake()->lastName(),
            'img_path' => null,
            'birth_date' => fake()->dateTimeBetween('-50 years', '-18 years')->format('Y-m-d'),
            'gender' => $gender,
            'city' => fake()->city(),
        ];
    }
}
