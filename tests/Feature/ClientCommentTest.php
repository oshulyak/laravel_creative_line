<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientCommentTest extends TestCase {
    use RefreshDatabase;

    public function test_comments_arrive_by_pages(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        // for($post, 'commentable') — привязка к полиморфному родителю: фабрика
        // сама проставит commentable_id и commentable_type.
        Comment::factory()
            ->count(12)
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        $response = $this->actingAs(Profile::factory()->create()->user)
            ->getJson(route('client.posts.comments.index', $post));

        $response->assertOk()
            // Ровно 10 на странице — это и есть контракт, на который опирается клиент.
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_comments_on_moderation_are_hidden(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        Comment::factory()
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_MODERATE]);

        $response = $this->actingAs(Profile::factory()->create()->user)
            ->getJson(route('client.posts.comments.index', $post));

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_user_adds_comment(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $response = $this->actingAs($profile->user)
            ->postJson(route('client.posts.comments.store', $post), [
                // Отправляем ТОЛЬКО content — ровно то, что есть в форме.
                'content' => 'Первый!',
            ]);

        $response->assertCreated()->assertJsonPath('content', 'Первый!');

        // Проверяем не ответ, а базу: важно, что автором стал профиль из сессии,
        // а статус проставил сервер. Именно это правило легко сломать правкой запроса.
        $this->assertDatabaseHas('comments', [
            'commentable_id' => $post->id,
            'commentable_type' => Post::class,
            'author_id' => $profile->id,
            'content' => 'Первый!',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
    }

    public function test_comment_author_is_taken_from_session(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();
        $stranger = Profile::factory()->create();

        // Пробуем подписаться чужим профилем — поля author_id в форме нет,
        // но подставить его в запрос руками ничто не мешает.
        $this->actingAs($profile->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Не я это написал',
                'author_id' => $stranger->id,
            ])
            ->assertCreated();

        // prepareForValidation() перетирает пришедшее значение своим,
        // поэтому автором остался тот, кто залогинен.
        $this->assertDatabaseHas('comments', [
            'content' => 'Не я это написал',
            'author_id' => $profile->id,
        ]);
    }

    public function test_comment_content_is_required(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.posts.comments.store', $post), ['content' => ''])
            ->assertJsonValidationErrors('content');
    }

    public function test_like_on_comment_toggles(): void {
        $comment = Comment::factory()->create();
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.comments.likes.toggle', $comment))
            ->assertOk()
            ->assertJson(['is_liked' => true, 'likes_count' => 1]);

        // Второй клик по той же кнопке снимает лайк — это и есть toggle.
        $this->actingAs($profile->user)
            ->postJson(route('client.comments.likes.toggle', $comment))
            ->assertOk()
            ->assertJson(['is_liked' => false, 'likes_count' => 0]);
    }

    public function test_replies_arrive_without_moderated(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $comment = Comment::factory()
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        // Тот же for(..., 'commentable'), только родитель теперь комментарий.
        // Ровно этим ответ и отличается от комментария в базе.
        Comment::factory()
            ->count(3)
            ->for($comment, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        Comment::factory()
            ->for($comment, 'commentable')
            ->create(['status' => Comment::STATUS_MODERATE]);

        $this->actingAs(Profile::factory()->create()->user)
            ->getJson(route('client.comments.replies.index', $comment))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_replies_do_not_leak_into_post_comments(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $comment = Comment::factory()
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        Comment::factory()
            ->count(2)
            ->for($comment, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        $this->actingAs(Profile::factory()->create()->user)
            ->getJson(route('client.posts.comments.index', $post))
            ->assertOk()
            // Самый ценный тест урока: список поста показывает ОДНУ запись,
            // хотя в таблице их три. Ответы в него не попали — это работа
            // полиморфной связи, и сломать её легко неаккуратным where().
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.replies_count', 2);
    }

    public function test_user_replies_to_comment(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $comment = Comment::factory()
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.comments.replies.store', $comment), [
                'content' => 'Согласен',
            ])
            ->assertCreated()
            ->assertJsonPath('content', 'Согласен');

        // Проверяем родителя: и id, и тип. Без commentable_type ответ был бы
        // неотличим от комментария к посту с тем же номером.
        $this->assertDatabaseHas('comments', [
            'commentable_id' => $comment->id,
            'commentable_type' => Comment::class,
            'author_id' => $profile->id,
            'content' => 'Согласен',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
    }

    public function test_reply_to_reply_is_not_allowed(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $comment = Comment::factory()
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);
        $reply = Comment::factory()
            ->for($comment, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        // Кнопки в интерфейсе нет, но запрос отправить ничто не мешает —
        // и вот это правило проверяет, что «один уровень» живёт на сервере,
        // а не только в разметке.
        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.comments.replies.store', $reply), [
                'content' => 'Третий уровень',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('comments', ['content' => 'Третий уровень']);
    }
}
