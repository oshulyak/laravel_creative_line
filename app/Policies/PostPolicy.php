<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

/**
 * Правила доступа к постам.
 *
 * Laravel находит политику сам по имени: App\Models\Post → App\Policies\PostPolicy.
 * Спрашивают её Gate::authorize() в контроллере и $user->can() в ресурсе.
 */
class PostPolicy {
    /**
     * Удалить пост может только его автор.
     *
     * Первым аргументом Laravel передаёт вошедшего пользователя. Гостя он
     * отклонит сам, до вызова метода: тайп-хинт User без ? гостя не принимает.
     *
     * posts.author_id ссылается на профиль, а не на пользователя, поэтому
     * сравниваем с id профиля. У пользователя без профиля слева будет null,
     * и сравнение с author_id (он NOT NULL) даст false.
     */
    public function delete(User $user, Post $post): bool {
        return $user->profile?->id === $post->author_id;
    }
}
