<?php

namespace Tests\Feature;

use App\Events\WS\SendNotificationEvent;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ClientNotificationTest extends TestCase {
    use RefreshDatabase;

    public function test_comment_creates_notification_for_post_author(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Отличная статья',
            ])
            ->assertCreated();

        // Проверяем не «есть хоть какая-то строка», а адресата и источник:
        // перепутать profile_id получателя с actor_id инициатора — самая
        // вероятная ошибка в этом коде.
        $this->assertDatabaseHas('app_notifications', [
            'profile_id' => $post->author_id,
            'actor_id' => $profile->id,
            'notificationable_type' => Comment::class,
        ]);
    }

    public function test_own_comment_does_not_create_notification(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        $this->actingAs($post->author->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Сам себе отвечу',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_repost_creates_notification_for_original_author(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.reposts.store', $post), [
                'title' => 'Смотрите, что нашёл',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('app_notifications', [
            'profile_id' => $post->author_id,
            'actor_id' => $profile->id,
            'notificationable_type' => Post::class,
        ]);
    }

    public function test_like_creates_notification_for_post_author(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.likes.toggle', $post))
            ->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'profile_id' => $post->author_id,
            'actor_id' => $profile->id,
            'notificationable_type' => Post::class,
            'notificationable_id' => $post->id,
        ]);
    }

    /**
     * Лайк снимают и ставят заново — уведомление должно остаться одно.
     *
     * Это проверка firstOrCreate в LikeObserver: без него после трёх кликов
     * автор получил бы два одинаковых сообщения.
     */
    public function test_repeated_like_does_not_duplicate_notification(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.likes.toggle', $post))
            ->assertOk();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.likes.toggle', $post))
            ->assertOk();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.likes.toggle', $post))
            ->assertOk();

        $this->assertDatabaseCount('app_notifications', 1);
    }

    public function test_like_on_comment_notifies_its_author(): void {
        $comment = Comment::factory()->create(['status' => Comment::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.comments.likes.toggle', $comment))
            ->assertOk();

        // assertDatabaseHas, а не assertDatabaseCount: Comment::factory() сам
        // поднял CommentObserver, и одна строка в таблице была ещё до лайка.
        $this->assertDatabaseHas('app_notifications', [
            'profile_id' => $comment->author_id,
            'actor_id' => $profile->id,
            'notificationable_type' => Comment::class,
            'notificationable_id' => $comment->id,
        ]);
    }

    public function test_own_like_does_not_create_notification(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        $this->actingAs($post->author->user)
            ->postJson(route('client.posts.likes.toggle', $post))
            ->assertOk();

        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_index_returns_own_notifications_and_marks_them_read(): void {
        $profile = Profile::factory()->create();
        $stranger = Profile::factory()->create();

        $mine = Notification::factory()->create(['profile_id' => $profile->id]);
        $foreign = Notification::factory()->create(['profile_id' => $stranger->id]);

        $this->actingAs($profile->user)
            ->getJson(route('client.profiles.notifications.index'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $mine->id]);

        // Состояние проверяем запросом к таблице, а НЕ через $mine->fresh().
        // fresh() достаёт модель из базы, то есть поднимает событие retrieved —
        // и NotificationObserver прямо в момент проверки проставил бы read_at.
        // Тест стал бы зелёным независимо от того, работает код или нет.
        $this->assertDatabaseMissing('app_notifications', [
            'id' => $mine->id,
            'read_at' => null,
        ]);

        // Чужое не тронуто: его из базы никто не доставал.
        $this->assertDatabaseHas('app_notifications', [
            'id' => $foreign->id,
            'read_at' => null,
        ]);
    }

    public function test_new_notification_is_broadcast_to_recipient(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        Event::fake([SendNotificationEvent::class]);

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.likes.toggle', $post))
            ->assertOk();

        Event::assertDispatched(SendNotificationEvent::class, function (SendNotificationEvent $event) use ($post): bool {
            // Канал АВТОРА поста, а не того, кто лайкнул: перепутать profile_id
            // и actor_id — самая вероятная ошибка в этом событии.
            $this->assertSame('private-profiles.'.$post->author_id.'.notifications', $event->broadcastOn()[0]->name);

            // Число уже учитывает новое уведомление: событие отправлено после
            // вставки строки.
            $this->assertSame(1, $event->broadcastWith()['notifications_count']);

            return true;
        });
    }

    /**
     * Правило вызываем напрямую — почему, см. ClientMessageTest.
     */
    public function test_only_owner_can_listen_to_notifications_channel(): void {
        $owner = Profile::factory()->create();

        $canListen = Broadcast::driver()->getChannels()->get('profiles.{profile}.notifications');

        $this->assertTrue($canListen($owner->user, $owner));
        $this->assertFalse($canListen(Profile::factory()->create()->user, $owner));
    }
}
