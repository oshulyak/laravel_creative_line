<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Repost\StoreRequest;
use App\Http\Resources\Post\PostResource;
use App\Models\Post;
use App\Services\PostService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
        //
        // parent.author — точечная запись для вложенной связи: «загрузи родителя,
        // а у родителя — автора». Без неё в строке «Репост: …» не будет ника автора
        // оригинала. У обычного поста связь просто вернёт null.
        $post->load(['author', 'category', 'images', 'tags', 'parent.author']);
        $post->loadCount(['likedByProfiles', 'reposts']);
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

    /**
     * Репост публикации.
     *
     * Репостить можно только опубликованное. Свой пост на модерации автор открыть
     * может (см. show()), но репост из него сделал бы черновик публичным в обход
     * модерации — поэтому проверяем именно статус, без поблажки автору.
     *
     * 404, а не 403: наружу это выглядит как «репостить нечего».
     *
     * Репост репоста не запрещаем: у каждого репоста свой автор и свой заголовок,
     * а parent_id указывает на непосредственный источник — цепочка ничего не ломает.
     */
    public function storeRepost(StoreRequest $request, Post $post): JsonResponse {
        abort_unless($post->status === Post::STATUS_PUBLISHED, 404);

        // parent_id проставит сама связь: она знает id родителя. Дублировать это
        // в FormRequest значило бы завести второй источник правды.
        $post->reposts()->create($request->validated());

        // Возвращаем не созданный пост, а состояние оригинала: карточка репоста
        // на этой странице не появляется — он уедет в «Мои публикации», — а вот
        // счётчик под иконкой обновить нужно. Тот же формат, что у toggleLike():
        // маленький массив вместо ресурса.
        //
        // Считаем запросом в базу, а не ++ на клиенте: пока страница была открыта,
        // репостнуть мог кто-то ещё.
        return response()->json([
            'reposts_count' => $post->reposts()->count(),
        ], 201);
    }

    /**
     * Удаление своего поста.
     *
     * Алиас HttpResponse в импортах нужен из-за коллизии: Response в этом файле —
     * это Inertia\Response, его возвращает show().
     *
     * @see \App\Http\Controllers\Admin\PostController::destroy()
     */
    public function destroy(Request $request, Post $post): HttpResponse {
        // Единственное отличие от админского destroy() — проверка авторства.
        //
        // 403, а не 404 как в show(): существование поста уже не тайна, пользователь
        // видел его в ленте. Скрывать нечего, отказать нужно честно.
        //
        // Сравниваем author_id с id ПРОФИЛЯ, а не пользователя: posts.author_id
        // ссылается на profiles.id. Профиля может не быть — тогда ?-> даст null,
        // сравнение с author_id (он NOT NULL) будет ложным, и получится тот же 403.
        abort_unless($post->author_id === $request->user()->profile?->id, 403);

        // Тот же сервис, что и в админке. Он снимает связи, стирает файлы картинок
        // и двигает версию кэша списка — клиентскому контроллеру не пришлось написать
        // ни строчки этой логики.
        PostService::destroy($post);

        // 204 No Content: тела у ответа нет, клиент и так знает, что удалял.
        return response()->noContent();
    }
}
