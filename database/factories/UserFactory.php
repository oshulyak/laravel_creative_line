<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory {
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Пользователь с ролью admin.
     *
     * afterCreating — роль привязывается к уже сохранённому пользователю:
     * у несохранённого нет id для role_user.
     *
     * firstOrCreate — роль admin одна на всю базу, даже если в тесте несколько
     * администраторов. Уникального индекса у roles.title нет, и create() завёл бы дубли.
     */
    public function admin(): static {
        return $this->afterCreating(function (User $user): void {
            $user->roles()->attach(Role::firstOrCreate(['title' => 'admin']));
        });
    }
}
