<?php

namespace Tests\Feature;

use App\Events\WS\SendMessageEvent;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ClientMessageTest extends TestCase {
    use RefreshDatabase;

    public function test_participant_sends_message(): void {
        $viewer = Profile::factory()->create();
        $companion = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $companion->id]);

        // postJson: форма отправляет запрос через axios и ждёт JSON.
        $this->actingAs($viewer->user)
            ->postJson(route('client.chats.messages.store', $chat), [
                'content' => 'Привет!',
            ])
            ->assertCreated()
            ->assertJsonPath('content', 'Привет!')
            // Автор нужен клиенту сразу: иначе под новым сообщением не будет ника.
            ->assertJsonPath('author.nickname', $viewer->nickname)
            // JSON сообщения один для всех участников: флагов, посчитанных
            // для текущего пользователя, в авторе быть не должно. Упадёт,
            // если вместо ProfileSummaryResource вложить ProfileResource.
            ->assertJsonMissingPath('author.can_message');

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'author_id' => $viewer->id,
            'content' => 'Привет!',
        ]);
    }

    public function test_message_author_is_taken_from_session(): void {
        $viewer = Profile::factory()->create();
        $companion = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $companion->id]);

        // Поля author_id в форме нет, но подставить его в запрос руками
        // ничто не мешает.
        $this->actingAs($viewer->user)
            ->postJson(route('client.chats.messages.store', $chat), [
                'content' => 'Это написал не он',
                'author_id' => $companion->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('messages', ['author_id' => $viewer->id]);
        $this->assertDatabaseMissing('messages', ['author_id' => $companion->id]);
    }

    /**
     * Текст намеренно пустой. Если проверка участия стоит в authorize(),
     * посторонний получит 403 до валидации. Если её перенесут в контроллер,
     * валидация успеет раньше, и тест упадёт на 422.
     */
    public function test_stranger_is_forbidden_before_validation(): void {
        $chat = Chat::factory()->create();
        $chat->profiles()->attach([
            Profile::factory()->create()->id,
            Profile::factory()->create()->id,
        ]);

        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.chats.messages.store', $chat), [
                'content' => '',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_empty_message_is_rejected(): void {
        $viewer = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, Profile::factory()->create()->id]);

        $this->actingAs($viewer->user)
            ->postJson(route('client.chats.messages.store', $chat), [
                'content' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    public function test_chat_page_contains_messages_of_this_chat(): void {
        $viewer = Profile::factory()->create();
        $companion = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $companion->id]);

        Message::factory()->for($chat)->for($companion, 'author')->create(['content' => 'Привет!']);
        Message::factory()->for($chat)->for($viewer, 'author')->create(['content' => 'И тебе привет']);

        // Сообщение из другого чата: на этой странице его быть не должно.
        Message::factory()->create();

        $this->actingAs($viewer->user)
            ->get(route('client.chats.show', $chat))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Chat/Show')
                ->has('chat')
                ->has('messages', 2)
                ->where('messages.0.content', 'Привет!')
                // Ник пришёл — значит, маппер загрузил автора.
                ->where('messages.0.author.nickname', $companion->nickname)
                ->etc());
    }

    public function test_sent_message_is_broadcast_to_other_participants(): void {
        $viewer = Profile::factory()->create();
        $companion = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $companion->id]);

        // Подменяем только это событие: всё остальное работает по-настоящему.
        Event::fake([SendMessageEvent::class]);

        $this->actingAs($viewer->user)
            // В браузере этот заголовок добавляет Echo. По нему toOthers()
            // запоминает, какой вкладке событие не отправлять.
            ->withHeader('X-Socket-ID', '1234.5678')
            ->postJson(route('client.chats.messages.store', $chat), [
                'content' => 'Привет!',
            ])
            ->assertCreated();

        Event::assertDispatched(SendMessageEvent::class, function (SendMessageEvent $event) use ($chat, $viewer): bool {
            // Канал именно этого чата: ошибка в имени — и собеседник ничего не получит.
            $this->assertSame('private-chats.'.$chat->id.'.messages', $event->broadcastOn()[0]->name);

            // Вкладка отправителя исключена. Без toOthers() здесь был бы null,
            // и сообщение встало бы в её ленту дважды.
            $this->assertSame('1234.5678', $event->socket);

            // В данных — сообщение с автором: load('author') стоит раньше broadcast().
            $message = $event->broadcastWith()['message'];
            $this->assertSame('Привет!', $message['content']);
            $this->assertSame($viewer->nickname, $message['author']['nickname']);

            return true;
        });
    }

    /**
     * Правило вызываем напрямую, а не запросом на /broadcasting/auth:
     * в тестах BROADCAST_CONNECTION=null (phpunit.xml), а драйвер null пускает
     * в любой канал кого угодно — HTTP-тест был бы зелёным при любом правиле.
     */
    public function test_only_participants_can_listen_to_chat_channel(): void {
        $viewer = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, Profile::factory()->create()->id]);

        // Правила из routes/channels.php хранятся по шаблону имени канала.
        $canListen = Broadcast::driver()->getChannels()->get('chats.{chat}.messages');

        $this->assertTrue($canListen($viewer->user, $chat));
        $this->assertFalse($canListen(Profile::factory()->create()->user, $chat));
    }
}
