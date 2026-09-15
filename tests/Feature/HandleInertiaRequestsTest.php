<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HandleInertiaRequestsTest extends TestCase {
    use RefreshDatabase;

    /**
     * Проп стал замыканием — страница всё равно должна получить значение.
     * Прочитанное уведомление в счёт не входит.
     */
    public function test_page_receives_unread_notifications_count(): void {
        $viewer = Profile::factory()->create();
        Notification::factory()->count(2)->for($viewer)->create();
        Notification::factory()->for($viewer)->create(['read_at' => now()]);

        $response = $this->actingAs($viewer->user)->get(route('client.feed.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('auth.user.profile.notifications_count', 2)
            ->etc());
    }

    /**
     * Главная находка Telescope. Если из share() уберут fn () =>, счётчик
     * снова будет считаться для каждого JSON-ответа, и тест упадёт,
     * показав лишний SQL.
     */
    public function test_json_request_does_not_count_notifications(): void {
        $viewer = Profile::factory()->create();

        DB::enableQueryLog();

        // Поиск профилей — JSON для axios, уведомлений он не создаёт.
        $this->actingAs($viewer->user)
            ->getJson(route('client.profiles.index'))
            ->assertOk();

        $notificationQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'app_notifications'))
            ->values()
            ->all();

        $this->assertSame([], $notificationQueries);
    }
}
