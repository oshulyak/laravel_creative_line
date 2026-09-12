<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientRepostTest extends TestCase {
    use RefreshDatabase;

    public function test_user_reposts_post(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.reposts.store', $post), [
                'title' => 'Смотрите, что нашёл',
            ])
            ->assertCreated()
            ->assertJsonPath('reposts_count', 1);

        // Проверяем всё, что подставил сервер: родителя, автора из сессии
        // и скопированный текст. parent_id тут — главная строка теста: забытый
        // 'parent_id' в $fillable дал бы обычный пост без родителя, и ошибка
        // не проявилась бы ничем, кроме этой проверки.
        $this->assertDatabaseHas('posts', [
            'parent_id' => $post->id,
            'author_id' => $profile->id,
            'title' => 'Смотрите, что нашёл',
            'content' => $post->content,
            'status' => Post::STATUS_PUBLISHED,
        ]);
    }

    public function test_repost_requires_free_title(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        // Заголовок оригинала занят — именно из-за unique в схеме модалка
        // и спрашивает новый. Без правила unique тот же запрос дал бы 500.
        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.posts.reposts.store', $post), [
                'title' => $post->title,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_moderated_post_cannot_be_reposted(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_MODERATE]);

        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.posts.reposts.store', $post), [
                'title' => 'Черновик наружу',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('posts', ['title' => 'Черновик наружу']);
    }

    public function test_repost_appears_in_personal_posts(): void {
        $original = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.reposts.store', $original), [
                'title' => 'Мой репост',
            ])
            ->assertCreated();

        // Пункт задания целиком: репост лежит в «Моих публикациях» среди обычных
        // постов, и в карточке видно, с чего он сделан. Проверяем пропсы страницы,
        // а не HTML: страницу рисует Vue, сервер отдаёт данные.
        //
        // ->etc() говорит «остальные ключи меня не интересуют»: без него проверка
        // требует перечислить их все.
        $this->actingAs($profile->user)
            ->get(route('client.profiles.personal'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('posts.data', 1)
                ->where('posts.data.0.title', 'Мой репост')
                ->where('posts.data.0.parent.title', $original->title)
                ->etc());
    }

    public function test_repost_survives_deletion_of_original(): void {
        $original = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $repost = Post::factory()->create(['parent_id' => $original->id]);

        // Автор удаляет свой пост, у которого есть чужой репост. Без nullOnDelete()
        // этот запрос упал бы 500-й от внешнего ключа, а с cascadeOnDelete()
        // удалил бы чужую публикацию.
        $this->actingAs($original->author->user)
            ->deleteJson(route('client.posts.destroy', $original))
            ->assertNoContent();

        $this->assertDatabaseHas('posts', [
            'id' => $repost->id,
            'parent_id' => null,
        ]);
    }
}
