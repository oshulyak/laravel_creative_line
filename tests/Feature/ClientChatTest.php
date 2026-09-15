<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientChatTest extends TestCase {
    use RefreshDatabase;

    public function test_message_button_creates_chat_with_both_profiles(): void {
        $viewer = Profile::factory()->create();
        $author = Profile::factory()->create();

        // post(), а не postJson(): кнопка отправляет обычный запрос и ждёт
        // редирект, JSON в ответе не предполагается.
        $response = $this->actingAs($viewer->user)
            ->post(route('client.profiles.chats.store', $author));

        // sole() — «ровно одна запись, иначе исключение»: заодно проверяет,
        // что чат создан один.
        $chat = Chat::sole();

        $response->assertRedirect(route('client.chats.show', $chat));

        $this->assertDatabaseHas('chat_profile', ['chat_id' => $chat->id, 'profile_id' => $viewer->id]);
        $this->assertDatabaseHas('chat_profile', ['chat_id' => $chat->id, 'profile_id' => $author->id]);
    }

    public function test_existing_chat_is_reused(): void {
        $viewer = Profile::factory()->create();
        $author = Profile::factory()->create();

        // Участников добавляем напрямую через связь: тест проверяет повторное
        // нажатие, а не создание чата.
        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $author->id]);

        $this->actingAs($viewer->user)
            ->post(route('client.profiles.chats.store', $author))
            ->assertRedirect(route('client.chats.show', $chat));

        $this->assertDatabaseCount('chats', 1);
    }

    /**
     * Самая вероятная ошибка в storeChat() — потерять whereHas() и взять
     * «первый попавшийся мой чат». Предыдущий тест её не заметит: там чат
     * у смотрящего один, и он как раз нужный.
     */
    public function test_chat_with_another_profile_is_not_reused(): void {
        $viewer = Profile::factory()->create();
        $author = Profile::factory()->create();

        $otherChat = Chat::factory()->create();
        $otherChat->profiles()->attach([$viewer->id, Profile::factory()->create()->id]);

        $this->actingAs($viewer->user)
            ->post(route('client.profiles.chats.store', $author))
            ->assertRedirect();

        $this->assertDatabaseCount('chats', 2);
        $this->assertDatabaseHas('chat_profile', ['profile_id' => $author->id]);
    }

    /**
     * С групповыми чатами «мой чат, где есть он» может оказаться общим.
     * «Написать» должна вести в личный диалог, а не туда.
     */
    public function test_message_button_does_not_reuse_group_chat(): void {
        $viewer = Profile::factory()->create();
        $author = Profile::factory()->create();

        $groupChat = Chat::factory()->create(['title' => 'Работа']);
        $groupChat->profiles()->attach([$viewer->id, $author->id]);

        $response = $this->actingAs($viewer->user)
            ->post(route('client.profiles.chats.store', $author));

        // Появился новый чат без названия — диалог, и редирект ведёт в него.
        $dialog = Chat::query()->whereNull('title')->sole();

        $response->assertRedirect(route('client.chats.show', $dialog));
    }

    public function test_user_cannot_start_chat_with_himself(): void {
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->post(route('client.profiles.chats.store', $profile))
            ->assertForbidden();

        $this->assertDatabaseCount('chats', 0);
    }

    public function test_participant_opens_chat_page(): void {
        $viewer = Profile::factory()->create();
        $author = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $author->id]);

        // Заголовок диалога — ник собеседника, а не свой: проверяем именно это.
        $this->actingAs($viewer->user)
            ->get(route('client.chats.show', $chat))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Chat/Show')
                ->where('chat.title', $author->nickname)
                ->has('chat.profiles', 2)
                ->etc());
    }

    public function test_stranger_cannot_open_foreign_chat(): void {
        $chat = Chat::factory()->create();
        $chat->profiles()->attach([
            Profile::factory()->create()->id,
            Profile::factory()->create()->id,
        ]);

        $this->actingAs(Profile::factory()->create()->user)
            ->get(route('client.chats.show', $chat))
            ->assertForbidden();
    }
}
