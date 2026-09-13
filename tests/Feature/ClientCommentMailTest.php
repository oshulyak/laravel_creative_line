<?php

namespace Tests\Feature;

use App\Mail\Comment\StoreCommentMail;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientCommentMailTest extends TestCase {
    use RefreshDatabase;

    public function test_post_author_is_notified_about_new_comment(): void {
        // Mail::fake() подменяет почтовый драйвер заглушкой: письма никуда
        // не уходят, но фасад запоминает всё, что его просили отправить.
        Mail::fake();

        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Отличная статья',
            ])
            ->assertCreated();

        // assertSent — потому что письмо отправляется синхронно, через send().
        // Парный assertQueued ловит только письма, ушедшие в очередь, и здесь
        // сообщил бы «письмо не отправлено», хотя проверял бы не тот список.
        //
        // Замыкание проверяет адресата (hasTo — помощник самого Mailable)
        // и данные внутри письма. $mail->comment доступен потому, что свойства
        // конструктора объявлены public.
        Mail::assertSent(StoreCommentMail::class, function (StoreCommentMail $mail) use ($post) {
            return $mail->hasTo($post->author->user->email)
                && $mail->comment->content === 'Отличная статья';
        });
    }

    public function test_author_is_not_notified_about_own_comment(): void {
        Mail::fake();

        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        // Комментирует сам автор поста.
        $this->actingAs($post->author->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Сам себе отвечу',
            ])
            ->assertCreated();

        Mail::assertNothingSent();
    }

    /**
     * Содержимое письма тестируем отдельно от факта отправки: это разные вопросы,
     * и HTTP-запрос ради проверки вёрстки делать незачем. Mailable рендерится сам,
     * прямо из объекта.
     *
     * Заголовок и текст заданы явно, а не взяты из фабрики: fake()->sentence()
     * может вернуть строку с апострофом, Blade экранирует его в &#039;,
     * и assertSeeInHtml() не найдёт исходную подстроку.
     */
    public function test_mail_shows_post_title_and_comment(): void {
        $post = Post::factory()->create([
            'title' => 'Первый пост',
            'status' => Post::STATUS_PUBLISHED,
        ]);

        $comment = Comment::factory()
            ->for($post, 'commentable')
            ->create([
                'content' => 'Очень интересно',
                'status' => Comment::STATUS_PUBLISHED,
            ]);

        $mailable = new StoreCommentMail($post, $comment);

        $mailable->assertHasSubject('Новый комментарий к вашей публикации');
        $mailable->assertSeeInHtml('Первый пост');
        $mailable->assertSeeInHtml('Очень интересно');
    }
}
