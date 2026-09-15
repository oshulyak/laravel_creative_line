<?php

namespace Tests\Feature;

use App\Models\Statistic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase {
    use RefreshDatabase;

    public function test_dashboard_shows_last_30_days_newest_first(): void {
        $this->travelTo('2026-09-13 10:00:00');

        // 31 день подряд, создаём от сегодняшнего назад: id растут, а даты убывают.
        // Сортировка по id вместо date перевернула бы список, и тест это поймал бы.
        Statistic::factory()
            ->count(31)
            ->sequence(fn (Sequence $sequence) => ['date' => now()->subDays($sequence->index)->toDateString()])
            ->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboard/Index')
                ->has('statistics', 30)
                ->where('statistics.0.date', '2026-09-13')
                // Тридцатая строка — 15 августа; 14 августа в выборку не попало.
                ->where('statistics.29.date', '2026-08-15')
                ->etc());
    }
}
