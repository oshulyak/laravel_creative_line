<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Profile;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientGroupTest extends TestCase {
    use RefreshDatabase;

    /**
     * Упадёт, если каталог строить от $viewer->groups(), как на уроке:
     * группы, где смотрящего нет, в список не попадут.
     */
    public function test_groups_page_lists_all_groups_with_subscription_state(): void {
        $viewer = Profile::factory()->create();

        $joined = Group::factory()->create();
        $joined->subscribers()->attach($viewer->id);

        $other = Group::factory()->create();

        $this->actingAs($viewer->user)
            ->get(route('client.groups.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Group/Index')
                ->has('groups', 2)
                // latest('id'): группа, созданная последней, — первая в списке.
                ->where('groups.0.id', $other->id)
                ->where('groups.0.is_subscribed', false)
                ->where('groups.0.subscribers_count', 0)
                ->where('groups.1.id', $joined->id)
                ->where('groups.1.is_subscribed', true)
                ->where('groups.1.subscribers_count', 1)
                ->etc());
    }

    public function test_group_is_created_with_creator_as_subscriber(): void {
        $viewer = Profile::factory()->create();

        // post(), а не postJson(): форму отправляет Inertia и ждёт редирект.
        $response = $this->actingAs($viewer->user)
            ->post(route('client.groups.store'), [
                'title' => 'Laravel',
                'description' => 'Всё о фреймворке',
            ]);

        $group = Group::sole();

        $response->assertRedirect(route('client.groups.show', $group));

        $this->assertSame('Laravel', $group->title);
        // Вступать создатель не нажимал: его добавил GroupService.
        $this->assertTrue($group->hasSubscriber($viewer));
    }

    public function test_user_joins_and_leaves_group(): void {
        $viewer = Profile::factory()->create();
        $group = Group::factory()->create();

        // postJson: кнопка отправляет запрос через axios.
        $this->actingAs($viewer->user)
            ->postJson(route('client.groups.subscribers.toggle', $group))
            ->assertOk()
            ->assertJsonPath('is_subscribed', true)
            ->assertJsonPath('subscribers_count', 1);

        $this->actingAs($viewer->user)
            ->postJson(route('client.groups.subscribers.toggle', $group))
            ->assertOk()
            ->assertJsonPath('is_subscribed', false)
            ->assertJsonPath('subscribers_count', 0);

        $this->assertDatabaseCount('group_profile', 0);
    }

    /**
     * Смотрящий в группе не состоит: страницу он видеть должен, форму — нет.
     */
    public function test_group_page_shows_its_themes_to_non_subscriber(): void {
        $group = Group::factory()->create();
        Theme::factory()->for($group)->create(['title' => 'Первая тема']);

        // Тема другой группы: на этой странице её быть не должно.
        Theme::factory()->create();

        $this->actingAs(Profile::factory()->create()->user)
            ->get(route('client.groups.show', $group))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Group/Show')
                ->where('group.is_subscribed', false)
                ->has('themes', 1)
                ->where('themes.0.title', 'Первая тема')
                ->etc());
    }

    public function test_subscriber_creates_theme(): void {
        $viewer = Profile::factory()->create();
        $group = Group::factory()->create();
        $group->subscribers()->attach($viewer->id);

        $response = $this->actingAs($viewer->user)
            ->post(route('client.groups.themes.store', $group), [
                'title' => 'Вопросы новичков',
            ]);

        $theme = Theme::sole();

        $response->assertRedirect(route('client.themes.show', $theme));

        // Автор — из сессии, группа — из адреса: в форме ни того, ни другого нет.
        $this->assertDatabaseHas('themes', [
            'group_id' => $group->id,
            'author_id' => $viewer->id,
            'title' => 'Вопросы новичков',
        ]);
    }

    /**
     * Заголовок пустой намеренно. Если проверка участия стоит в authorize(),
     * посторонний получит 403 до валидации. Если её перенесут в контроллер,
     * валидация успеет раньше, и тест упадёт на редиректе с ошибкой.
     */
    public function test_non_subscriber_cannot_create_theme(): void {
        $group = Group::factory()->create();

        $this->actingAs(Profile::factory()->create()->user)
            ->post(route('client.groups.themes.store', $group), [
                'title' => '',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('themes', 0);
    }
}
