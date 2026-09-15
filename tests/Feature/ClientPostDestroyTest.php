<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPostDestroyTest extends TestCase {
    use RefreshDatabase;

    public function test_author_deletes_own_post(): void {
        $profile = Profile::factory()->create();
        // for($profile, 'author') — связь называется author, а не profile,
        // поэтому имя приходится указывать явно.
        $post = Post::factory()->for($profile, 'author')->create();

        // actingAs принимает пользователя, а не профиль: аутентификация в Laravel
        // работает с User, профиль — уже наша предметная область.
        //
        // deleteJson, а не delete: запрос уходит с Accept: application/json,
        // и при неудаче в отчёте будет видно тело ответа, а не HTML страницы ошибки.
        $response = $this->actingAs($profile->user)
            ->deleteJson(route('client.posts.destroy', $post));

        $response->assertNoContent();
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    public function test_stranger_cannot_delete_foreign_post(): void {
        $post = Post::factory()->create();
        $stranger = Profile::factory()->create();

        $response = $this->actingAs($stranger->user)
            ->deleteJson(route('client.posts.destroy', $post));

        $response->assertForbidden();
        // Проверяем не только код ответа, но и базу: 403 без этой строки
        // мог бы прийти уже ПОСЛЕ удаления.
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_feed_marks_only_own_posts_as_deletable(): void {
        $viewer = Profile::factory()->create();
        // Даты заданы явно: лента сортирует по published_at, и порядок
        // карточек в ответе должен быть известен заранее.
        $foreign = Post::factory()->create([
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $own = Post::factory()->for($viewer, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($viewer->user)->get(route('client.feed.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Client/Feed/Index')
            ->where('posts.data.0.id', $foreign->id)
            ->where('posts.data.0.can_delete', false)
            ->where('posts.data.1.id', $own->id)
            ->where('posts.data.1.can_delete', true)
            ->etc());
    }
}
