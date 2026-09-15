<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientGroupChatTest extends TestCase {
    use RefreshDatabase;

    public function test_chats_page_lists_only_own_chats(): void {
        $viewer = Profile::factory()->create();

        $ownChat = Chat::factory()->create(['title' => 'Работа']);
        $ownChat->profiles()->attach([$viewer->id, Profile::factory()->create()->id]);

        // Чат, в котором смотрящего нет: в его списке ему не место.
        $foreignChat = Chat::factory()->create(['title' => 'Чужой']);
        $foreignChat->profiles()->attach([
            Profile::factory()->create()->id,
            Profile::factory()->create()->id,
        ]);

        $this->actingAs($viewer->user)
            ->get(route('client.chats.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Chat/Index')
                ->has('chats', 1)
                ->where('chats.0.title', 'Работа')
                ->etc());
    }

    public function test_group_chat_is_created_with_creator_and_members(): void {
        $viewer = Profile::factory()->create();
        $first = Profile::factory()->create();
        $second = Profile::factory()->create();

        // post(), а не postJson(): форму отправляет Inertia и ждёт редирект.
        $response = $this->actingAs($viewer->user)
            ->post(route('client.chats.store'), [
                'title' => 'Работа',
                'members' => [$first->id, $second->id],
            ]);

        $chat = Chat::sole();

        $response->assertRedirect(route('client.chats.show', $chat));

        $this->assertSame('Работа', $chat->title);

        // Создатель в чате, хотя в форме его не было: его дописал StoreRequest.
        // Canonicalizing — порядок участников не важен.
        $this->assertEqualsCanonicalizing(
            [$viewer->id, $first->id, $second->id],
            $chat->profiles->modelKeys(),
        );
    }

    public function test_group_chat_requires_title_and_member(): void {
        $viewer = Profile::factory()->create();

        // from() — страница, с которой пришёл запрос. Туда Laravel вернёт
        // пользователя с ошибками: в браузере это /chats с открытым окном.
        //
        // members пустой, но после prepareForValidation() в нём окажется
        // создатель: required пройдёт, а min:2 — нет. Это и проверяем.
        $this->actingAs($viewer->user)
            ->from(route('client.chats.index'))
            ->post(route('client.chats.store'), [
                'title' => '',
                'members' => [],
            ])
            ->assertRedirect(route('client.chats.index'))
            ->assertSessionHasErrors(['title', 'members']);

        $this->assertDatabaseCount('chats', 0);
    }

    /**
     * members — строка с id настоящего профиля: integer и проверку
     * существования она прошла бы.
     *
     * Если prepareForValidation() приведёт её к массиву через (array), правило
     * array получит уже массив и ничего не заметит, и чат создастся. Тест
     * падает именно в этом случае.
     */
    public function test_group_chat_rejects_members_that_are_not_array(): void {
        $viewer = Profile::factory()->create();
        $member = Profile::factory()->create();

        $this->actingAs($viewer->user)
            ->post(route('client.chats.store'), [
                'title' => 'Работа',
                'members' => (string) $member->id,
            ])
            ->assertSessionHasErrors('members');

        $this->assertDatabaseCount('chats', 0);
    }

    public function test_group_chat_rejects_nonexistent_member(): void {
        $viewer = Profile::factory()->create();
        $member = Profile::factory()->create();

        $response = $this->actingAs($viewer->user)
            ->post(route('client.chats.store'), [
                'title' => 'Работа',
                'members' => [$member->id, 999999],
            ]);

        $response->assertSessionHasErrors([
            'members' => 'Среди участников есть несуществующий профиль.',
        ]);
        $this->assertDatabaseCount('chats', 0);
    }

    public function test_profile_search_is_case_insensitive_and_excludes_viewer(): void {
        // Ник смотрящего тоже подходит под поиск: так видно, что его
        // исключает whereKeyNot(), а не сам фильтр.
        $viewer = Profile::factory()->create(['nickname' => 'anna_viewer']);
        Profile::factory()->create(['nickname' => 'Anna']);
        Profile::factory()->create(['nickname' => 'boris']);

        // getJson: список запрашивает axios.
        $this->actingAs($viewer->user)
            ->getJson(route('client.profiles.index', ['search' => 'ann']))
            ->assertOk()
            ->assertJsonCount(1)
            // «ann» нашёл «Anna»: whereLike() по умолчанию не различает регистр.
            ->assertJsonPath('0.nickname', 'Anna');
    }
}
