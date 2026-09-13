<?php

namespace Tests\Feature;

use App\Jobs\SendCommentMailJob;
use App\Mail\Comment\StoreCommentMail;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientCommentMailTest extends TestCase {
    use RefreshDatabase;

    public function test_post_author_is_notified_about_new_comment(): void {
        // Queue::fake() подменяет очередь заглушкой: задачи никуда не уезжают
        // и НЕ выполняются, но фасад запоминает всё, что в него положили.
        // Без него сработал бы драйвер sync из phpunit.xml, задача выполнилась бы
        // тут же, и мы проверяли бы уже не постановку в очередь, а отправку письма.
        Queue::fake();

        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Отличная статья',
            ])
            ->assertCreated();

        // Замыкание проверяет, что задачу поставили именно для этого поста
        // и именно с этим комментарием. $job->post доступен потому, что
        // свойства конструктора объявлены public.
        Queue::assertPushed(SendCommentMailJob::class, function (SendCommentMailJob $job) use ($post) {
            return $job->post->is($post)
                && $job->comment->content === 'Отличная статья';
        });
    }

    public function test_author_is_not_notified_about_own_comment(): void {
        Queue::fake();

        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        // Комментирует сам автор поста.
        $this->actingAs($post->author->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Сам себе отвечу',
            ])
            ->assertCreated();

        Queue::assertNothingPushed();
    }

    /**
     * Задача проверяется отдельно от контроллера: это разные вопросы, и смешивать
     * их в одном тесте значит получить тест, который падает по двум причинам сразу.
     *
     * Никакого Queue::fake() здесь нет и быть не должно — мы не ставим задачу
     * в очередь, а выполняем её handle() напрямую, как это сделал бы воркер.
     */
    public function test_job_sends_mail_to_post_author(): void {
        Mail::fake();

        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $comment = Comment::factory()->for($post, 'commentable')->create();

        (new SendCommentMailJob($post, $comment))->handle();

        // assertSent — потому что внутри задачи письмо отправляется через send().
        // Парный assertQueued ловит только письма, поставленные в очередь самим
        // Mailable, и здесь сообщил бы «письмо не отправлено».
        Mail::assertSent(StoreCommentMail::class, function (StoreCommentMail $mail) use ($post) {
            return $mail->hasTo($post->author->user->email);
        });
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
