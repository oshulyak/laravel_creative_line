<?php

namespace App\Mail\Comment;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо автору публикации: «вашу публикацию прокомментировали».
 *
 * Сам класс письма об очереди ничего не знает: в очередь уходит не письмо,
 * а задача SendCommentMailJob, которая уже внутри воркера отправляет его через send().
 * Альтернатива — implements ShouldQueue на самом Mailable; мы выбрали задачу,
 * потому что условие «кому и когда писать» тогда живёт в одном понятном месте.
 */
class StoreCommentMail extends Mailable {
    /**
     * Оба трейта — из заготовки make:mail, оставляем как есть.
     * Queueable добавляет методы onQueue()/delay(), SerializesModels — правила
     * сериализации моделей. Письмо отправляется внутри задачи через send(),
     * поэтому само оно не сериализуется и трейты здесь не работают.
     */
    use Queueable, SerializesModels;

    /**
     * Свойства public не случайно: все публичные свойства Mailable автоматически
     * попадают в Blade-шаблон под своими именами — в store.blade.php сразу доступны
     * $post и $comment, ничего передавать руками не нужно.
     *
     * Альтернатива — сделать свойства protected и перечислить данные явно
     * в Content(with: [...]). Так делают, когда в шаблон нужно отдать не модель
     * целиком, а подготовленные значения.
     */
    public function __construct(
        public Post $post,
        public Comment $comment,
    ) {}

    /**
     * Конверт. Указываем только тему: отправителя берём из MAIL_FROM_ADDRESS,
     * получателя задаёт Mail::to() в SendCommentMailJob.
     *
     * Тема — обычная строка, а не заголовок поста: тема письма должна быть
     * понятна в списке входящих, и «Новый комментарий…» читается лучше,
     * чем случайное название чужой публикации.
     */
    public function envelope(): Envelope {
        return new Envelope(
            subject: 'Новый комментарий к вашей публикации',
        );
    }

    /**
     * Содержимое: имя Blade-шаблона через точки, как в любом view().
     * mail.comment.store → resources/views/mail/comment/store.blade.php.
     */
    public function content(): Content {
        return new Content(
            view: 'mail.comment.store',
        );
    }
}
