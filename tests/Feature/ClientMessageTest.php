<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
