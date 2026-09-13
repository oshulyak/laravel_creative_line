<?php

namespace Database\Factories;

use App\Models\Statistic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Statistic>
 */
class StatisticFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * Отношения выводятся из сгенерированных счётчиков, а не берутся случайными:
     * строка «4 лайка, 40 просмотров, отношение 0.9» выглядела бы в админке как баг.
     *
     * @return array<string, mixed>
     */
    public function definition(): array {
        $likesCount = fake()->numberBetween(0, 500);
        $viewsCount = fake()->numberBetween($likesCount, 5000);
        $commentsCount = fake()->numberBetween(0, 300);

        return [
            // unique(): у таблицы уникальный индекс по date, а случайные даты
            // из одного года совпадают куда чаще, чем кажется.
            'date' => fake()->unique()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'posts_count' => fake()->numberBetween(0, 1000),
            'reposts_count' => fake()->numberBetween(0, 100),
            'comments_count' => $commentsCount,
            'likes_count' => $likesCount,
            'views_count' => $viewsCount,
            'likes_to_views_ratio' => $viewsCount > 0 ? round($likesCount / $viewsCount, 4) : null,
            'likes_to_comments_ratio' => $commentsCount > 0 ? round($likesCount / $commentsCount, 4) : null,
        ];
    }
}
