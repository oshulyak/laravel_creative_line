<?php

namespace App\Observers;

use App\Models\Comment;
use App\Models\Post;

class CommentObserver {
    /**
     * Комментарий создан — уведомляем автора публикации.
     *
     * Обсервер, а не контроллер: уведомление должно появляться от ФАКТА
     * «комментарий создан», независимо от того, каким маршрутом его создали.
     */
    public function created(Comment $comment): void {
        $post = $comment->commentable;

        // Ответы в ветке пропускаем: задание говорит про комментарии к постам.
        // Уведомление автору родительского комментария — отдельная задача,
        // и решается она здесь же одной веткой, когда понадобится.
        if (! $post instanceof Post) {
            return;
        }

        // ВРЕМЕННО ОТКЛЮЧЕНО: чтобы уведомления было видно, комментируя собственный
        // пост одним аккаунтом. Вернуть после проверки интерфейса.
        //
        // Себе о себе не пишем — то же правило, что у письма в CommentController.
        // if ($post->author_id === $comment->author_id) {
        //     return;
        // }

        // create() на полиморфной связи сам проставит notificationable_type
        // и notificationable_id. Наше дело — получатель, инициатор и текст.
        //
        // firstOrCreate здесь не нужен: у каждого комментария свой id, и две
        // строки означают два разных события. Дедупликация нужна только лайкам.
        $comment->notifications()->create([
            'profile_id' => $post->author_id,
            'actor_id' => $comment->author_id,
            'body' => 'Новый комментарий к публикации «'.$post->title.'»',
        ]);
    }
}
