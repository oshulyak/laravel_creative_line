<?php

namespace App\Jobs;

use App\Mail\Comment\StoreCommentMail;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Отправляет автору публикации письмо о новом комментарии.
 *
 * implements ShouldQueue — то самое, что отличает задачу в очереди от обычного
 * класса. Без этого интерфейса dispatch() выполнил бы handle() прямо здесь
 * и сейчас, и мы бы ничего не выиграли. Заготовка make:job ставит его сама.
 */
class SendCommentMailJob implements ShouldQueue {
    /**
     * Queueable — набор трейтов сразу:
     *   Dispatchable       — статический dispatch() и его варианты;
     *   InteractsWithQueue — доступ к самой задаче изнутри handle(): attempts(), release(), fail();
     *   Queueable          — onQueue(), delay(), onConnection();
     *   SerializesModels   — в очередь уезжают не модели целиком, а класс и id;
     *                        воркер достаёт записи из базы заново.
     */
    use Queueable;

    /**
     * Конструктор — единственное место, где задача получает данные. Всё, что
     * здесь окажется, уедет в payload и вернётся к воркеру.
     *
     * Свойства public ради теста: проверка «задача поставлена именно для этого
     * поста» пишется тогда в одну строку ($job->post->is($post)).
     */
    public function __construct(
        public Post $post,
        public Comment $comment,
    ) {}

    /**
     * Тело задачи — ровно тот код, который раньше стоял в контроллере.
     *
     * Ничего «особенного для фона» здесь нет: разница только в том, КОГДА
     * и В КАКОМ процессе метод выполнится.
     *
     * send(), а не queue(): мы уже внутри очереди, торопиться больше некуда,
     * и ждать SMTP-сервер здесь совершенно нормально.
     *
     * Mail::to() ждёт объект с полями email и name — это User, а не Profile:
     * почта лежит в users. Отсюда цепочка author->user.
     */
    public function handle(): void {
        Mail::to($this->post->author->user)
            ->send(new StoreCommentMail($this->post, $this->comment));
    }
}
