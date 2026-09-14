<?php

namespace App\Http\Resources\Notification;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource {
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'body' => $this->body,
            // Не «прочитано ли сейчас», а «было ли прочитанным ДО этого запроса»:
            // к моменту работы ресурса NotificationObserver уже проставил read_at
            // всем показанным строкам, и вычислять признак по нему поздно.
            // Флаг wasUnread — публичное свойство модели, его ставит тот же обсервер.
            'is_read' => ! $this->wasUnread,
            'created_at' => $this->created_at,
            'url' => $this->buildUrl(),
        ];
    }

    /**
     * Куда ведёт уведомление.
     *
     * Ссылку строит сервер, а не клиент: только здесь известно, что источник —
     * комментарий, и что показать надо пост, которому он принадлежит. Собирать
     * такой разбор во Vue значило бы продублировать знание о схеме данных.
     *
     * null — допустимое значение: источник могли удалить, и тогда строка
     * показывается без ссылки, а не ведёт в 404.
     */
    private function buildUrl(): ?string {
        $source = $this->notificationable;

        return match (true) {
            // Пост — и когда его репостнули, и когда лайкнули: ведём на него.
            $source instanceof Post => route('client.posts.show', $source),
            // Комментарий сам по себе страницы не имеет: ведём на пост,
            // к которому он оставлен.
            $source instanceof Comment && $source->commentable instanceof Post => route('client.posts.show', $source->commentable),
            default => null,
        };
    }
}
