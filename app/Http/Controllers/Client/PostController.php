<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Post\PostResource;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Response;

class PostController extends Controller {
    /**
     * Страница отдельного поста.
     *
     * Тайп-хинт Post $post — неявная привязка модели: несуществующий id даёт 404
     * до контроллера. Имя параметра маршрута и имя аргумента обязаны совпадать.
     */
    public function show(Request $request, Post $post): Response {
        $profileId = $request->user()->profile?->id;

        // Пост на модерации по прямой ссылке не показываем — но автору свой пост открыть
        // можно. 404, а не 403: существование чужого неопубликованного поста — тоже
        // информация, и подтверждать её незачем.
        //
        // Правильное место для такого правила — PostPolicy::view(), политики идут дальше
        // по курсу. Пока проверка живёт в контроллере, и это осознанный временный шаг.
        abort_unless(
            $post->status === Post::STATUS_PUBLISHED || $post->author_id === $profileId,
            404,
        );

        // load/loadCount/loadExists — это with/withCount/withExists для уже полученной
        // модели: запрос строил контейнер, достроить его мы не можем, поэтому догружаем
        // отдельными запросами. Без них PostResource промолчит про связи и про лайки —
        // whenLoaded/whenCounted/whenHas просто не найдут данных.
        $post->load(['author', 'category', 'images', 'tags']);
        $post->loadCount('likedByProfiles');
        $post->loadExists([
            'likedByProfiles as is_liked' => fn (Builder $query) => $query->whereKey($profileId),
        ]);

        return inertia('Client/Post/Show', [
            'post' => PostResource::make($post)->resolve(),
        ]);
    }

    /**
     * Переключение лайка на посте.
     *
     * Возвращает массив, а не Inertia-страницу: запрос уходит от axios, страница остаётся
     * на месте, обновить нужно только сердечко и счётчик.
     *
     * FormRequest здесь не нужен: тела у запроса нет вообще — всё, что требуется,
     * приезжает из URL ({post}) и из сессии (текущий пользователь). Валидировать нечего.
     *
     * @return array<string, mixed>
     */
    public function toggleLike(Request $request, Post $post): array {
        $profile = $request->user()->profile;

        // Лайк принадлежит профилю, и без профиля операция невозможна. 403 с текстом —
        // честнее, чем 500 от обращения к null.
        abort_if($profile === null, 403, 'У пользователя нет профиля.');

        // toggle() сам решает, что делать: строка в likeables есть — удалить, нет —
        // вставить. Клиенту не приходится выбирать между «поставить» и «снять»
        // по возможно устаревшему состоянию.
        //
        // Возвращает ['attached' => [...], 'detached' => [...]] — готовый ответ на вопрос
        // «что в итоге произошло», отдельный exists()-запрос не нужен.
        $changes = $post->likedByProfiles()->toggle($profile->id);

        return [
            // Непустой attached означает, что строку вставили, то есть лайк теперь стоит.
            'is_liked' => $changes['attached'] !== [],
            // Счётчик считаем после переключения: клиенту нужно актуальное число,
            // а не то, что приехало с последней загрузкой страницы.
            'likes_count' => $post->likedByProfiles()->count(),
        ];
    }
}
