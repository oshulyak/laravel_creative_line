<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPostViewTest extends TestCase {
    use RefreshDatabase;

    public function test_opening_post_counts_view_without_touching_updated_at(): void {
        $this->travelTo('2026-09-12 10:00:00');
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        // Открываем пост на следующий день: обычный increment() переписал бы
        // updated_at на это время, и проверка ниже это поймала бы.
        $this->travelTo('2026-09-13 10:00:00');

        $this->actingAs(Profile::factory()->create()->user)
            ->get(route('client.posts.show', $post))
            ->assertOk();

        $post->refresh();
        $this->assertSame(1, $post->views_count);
        $this->assertSame('2026-09-12 10:00:00', $post->updated_at->toDateTimeString());
    }

    public function test_hidden_post_does_not_count_view(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_MODERATE]);

        // Чужой пост на модерации отдаёт 404 — такое открытие просмотром не считается.
        // Тест упадёт, если increment() переедет выше проверки видимости.
        $this->actingAs(Profile::factory()->create()->user)
            ->get(route('client.posts.show', $post))
            ->assertNotFound();

        $this->assertSame(0, $post->refresh()->views_count);
    }
}
