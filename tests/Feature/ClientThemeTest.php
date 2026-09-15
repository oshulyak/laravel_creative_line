<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\Theme;
use App\Models\ThemeMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientThemeTest extends TestCase {
    use RefreshDatabase;

    public function test_subscriber_sends_message(): void {
        $viewer = Profile::factory()->create();
        $theme = Theme::factory()->create();
        $theme->group->subscribers()->attach($viewer->id);

        // postJson: форма отправляет запрос через axios и ждёт JSON.
        $this->actingAs($viewer->user)
            ->postJson(route('client.themes.messages.store', $theme), [
                'content' => 'Привет!',
            ])
            ->assertCreated()
            ->assertJsonPath('content', 'Привет!')
            // Ник нужен сразу: иначе под новым сообщением будет «Аноним».
            ->assertJsonPath('author.nickname', $viewer->nickname);

        $this->assertDatabaseHas('theme_messages', [
            'theme_id' => $theme->id,
            'author_id' => $viewer->id,
            'content' => 'Привет!',
        ]);
    }

    /**
     * Текст пустой намеренно: 403 должен прийти раньше, чем 422.
     */
    public function test_non_subscriber_cannot_send_message(): void {
        $theme = Theme::factory()->create();

        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.themes.messages.store', $theme), [
                'content' => '',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('theme_messages', 0);
    }

    public function test_theme_page_shows_its_messages_to_non_subscriber(): void {
        $author = Profile::factory()->create();
        $theme = Theme::factory()->create();

        ThemeMessage::factory()->for($theme)->for($author, 'author')->create(['content' => 'Первое']);
        ThemeMessage::factory()->for($theme)->for($author, 'author')->create(['content' => 'Второе']);

        // Сообщение из другой темы: на этой странице его быть не должно.
        ThemeMessage::factory()->create();

        // Смотрящий в группе не состоит: читать это не мешает,
        // а is_subscribed = false спрячет форму.
        $this->actingAs(Profile::factory()->create()->user)
            ->get(route('client.themes.show', $theme))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Theme/Show')
                ->where('theme.group.is_subscribed', false)
                ->has('messages', 2)
                ->where('messages.0.content', 'Первое')
                // Ник пришёл — значит, маппер загрузил автора.
                ->where('messages.0.author.nickname', $author->nickname)
                ->etc());
    }
}
