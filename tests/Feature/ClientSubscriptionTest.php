<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientSubscriptionTest extends TestCase {
    use RefreshDatabase;

    public function test_user_subscribes_and_unsubscribes(): void {
        $subscriber = Profile::factory()->create();
        $author = Profile::factory()->create();

        // Первый клик — подписка.
        $this->actingAs($subscriber->user)
            ->postJson(route('client.profiles.subscribers.toggle', $author))
            ->assertOk()
            ->assertJsonPath('is_subscribed', true);

        $this->assertDatabaseHas('profile_subscriptions', [
            'subscriber_id' => $subscriber->id,
            'subscribing_id' => $author->id,
        ]);

        // Второй клик тем же пользователем — отписка. Проверяем именно пару
        // вызовов и именно направление колонок: переставленные ключи в связи
        // подписали бы «наоборот», и первая половина теста это поймала бы.
        $this->actingAs($subscriber->user)
            ->postJson(route('client.profiles.subscribers.toggle', $author))
            ->assertOk()
            ->assertJsonPath('is_subscribed', false);

        $this->assertDatabaseMissing('profile_subscriptions', [
            'subscriber_id' => $subscriber->id,
            'subscribing_id' => $author->id,
        ]);
    }

    public function test_user_cannot_subscribe_to_himself(): void {
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.profiles.subscribers.toggle', $profile))
            ->assertForbidden();

        $this->assertDatabaseCount('profile_subscriptions', 0);
    }

    public function test_profile_page_shows_subscription_state(): void {
        $subscriber = Profile::factory()->create();
        $author = Profile::factory()->create();

        // Подписываем напрямую через связь, а не через HTTP: тест проверяет
        // страницу, а не повторяет уже проверенное переключение.
        $subscriber->subscriptions()->attach($author->id);

        $this->actingAs($subscriber->user)
            ->get(route('client.profiles.show', $author))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Profile/Show')
                ->where('profile.is_subscribed', true)
                ->where('profile.can_subscribe', true)
                ->etc());
    }

    public function test_profile_page_hides_posts_on_moderation(): void {
        $author = Profile::factory()->create();

        Post::factory()->for($author, 'author')->create([
            'title' => 'Опубликованный',
            'status' => Post::STATUS_PUBLISHED,
        ]);
        Post::factory()->for($author, 'author')->create([
            'title' => 'На модерации',
            'status' => Post::STATUS_MODERATE,
        ]);

        // Чужой пост на модерации не должен попасть на страницу автора — это правило
        // легко потерять, скопировав выборку из personal(), где фильтра нет.
        $this->actingAs(Profile::factory()->create()->user)
            ->get(route('client.profiles.show', $author))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('posts.data', 1)
                ->where('posts.data.0.title', 'Опубликованный')
                ->etc());
    }
}
