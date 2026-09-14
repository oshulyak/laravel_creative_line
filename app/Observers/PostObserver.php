<?php

namespace App\Observers;

use App\Models\Post;

class PostObserver {
    /**
     * Пост создан. Уведомление нужно только если это репост: у обычной
     * публикации нет адресата.
     */
    public function created(Post $post): void {
        // $post->parent — связь из 27-го урока. У обычного поста она вернёт null,
        // и обсервер молча выйдет. Проверка по связи, а не по колонке parent_id:
        // родитель нужен нам дальше целиком — ради author_id и title.
        $original = $post->parent;

        if ($original === null) {
            return;
        }

        // ВРЕМЕННО ОТКЛЮЧЕНО: чтобы уведомления было видно, репостя собственную
        // публикацию одним аккаунтом. Вернуть после проверки интерфейса.
        //
        // Репост собственной публикации уведомления не порождает.
        // if ($original->author_id === $post->author_id) {
        //     return;
        // }

        // Источник — сам репост: ссылка из уведомления ведёт на него, и автор
        // оригинала видит, что именно опубликовали.
        $post->notifications()->create([
            'profile_id' => $original->author_id,
            'actor_id' => $post->author_id,
            'body' => 'Репост публикации «'.$original->title.'»',
        ]);
    }
}
