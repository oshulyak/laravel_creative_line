<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Post\PostResource;
use App\Http\Resources\Profile\ProfileResource;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Response;

class ProfileController extends Controller {
    /**
     * Личная страница: профиль и лента собственных публикаций.
     *
     * Класс в неймспейсе Client — рядом с корневым App\Http\Controllers\ProfileController
     * от Breeze, который отвечает за форму настроек аккаунта. Задачи разные, поэтому
     * и контроллеры разные.
     */
    public function personal(Request $request): Response {
        $profile = $request->user()->profile;

        abort_if($profile === null, 404);

        // Запрос строим от связи, а не от Post::query()->where('author_id', ...):
        // profiles → posts уже описана в модели, и условие по author_id она подставит сама.
        //
        // Фильтра по статусу здесь нет намеренно: свой пост на модерации автор видеть
        // должен — иначе он решит, что публикация пропала.
        // Карточка поста теперь одна на ленту и на эту страницу (ItemPost.vue),
        // значит и данные ей нужны одинаковые: без author в карточке будет «Аноним»,
        // без is_liked сердечко всегда останется пустым.
        $posts = $profile->posts()
            // author добавился к category: карточка показывает ник автора,
            // и на своей странице он тоже должен быть виден. Это один
            // дополнительный запрос на всю страницу, а не на каждый пост.
            //
            // parent.author добавился ради репостов: карточка подписывает их
            // строкой «Репост: <оригинал> · <автор>». Здесь это особенно важно —
            // именно на этой странице репосты и живут рядом с обычными постами.
            ->with(['author', 'category', 'parent.author'])
            ->withCount(['likedByProfiles', 'reposts'])
            // Ровно тот же подзапрос, что в ленте. Профиль здесь точно есть —
            // выше стоит abort_if(), — поэтому whereKey() получит настоящий id.
            ->withExists([
                'likedByProfiles as is_liked' => fn (Builder $query) => $query->whereKey($profile->id),
            ])
            ->latest('id')
            ->paginate(10);

        return inertia('Client/Profile/Personal', [
            'profile' => ProfileResource::make($profile)->resolve(),
            'posts' => PostResource::collection($posts)->response()->getData(true),
        ]);
    }

    /**
     * Страница чужого профиля: шапка с кнопкой подписки и публикации автора.
     *
     * Profile $profile приходит неявной привязкой модели — несуществующий id
     * даёт 404 до контроллера.
     */
    public function show(Request $request, Profile $profile): Response {
        // Профиль того, КТО смотрит. Может отсутствовать — тогда подписка
        // недоступна, но страницу показать всё равно нужно.
        $viewer = $request->user()->profile;

        // «Подписан ли смотрящий на этот профиль» — тот же приём, что is_liked
        // у постов: подзапрос EXISTS по связи, суженный до одного профиля.
        //
        // loadExists(), а не withExists(): модель уже получена привязкой маршрута,
        // достроить её запрос нельзя — догружаем признак отдельным запросом.
        //
        // Псевдоним as is_subscribed задаёт имя атрибута: без него он звался бы
        // subscribers_exists.
        //
        // whereKey(null) при отсутствии профиля даст сравнение с NULL,
        // не истинное ни для одной строки, — кнопка приедет в состоянии «не подписан».
        $profile->loadExists([
            'subscribers as is_subscribed' => fn (Builder $query) => $query->whereKey($viewer?->id),
        ]);

        $posts = $profile->posts()
            // Ключевое отличие от personal(): на ЧУЖОЙ странице показываем только
            // опубликованное. Пост на модерации видит лишь его автор — у себя
            // в «Моих публикациях». То же правило, что в ленте.
            ->where('status', Post::STATUS_PUBLISHED)
            // Набор связей и счётчиков диктует карточка ItemPost — тот же, что в personal().
            ->with(['author', 'category', 'parent.author'])
            ->withCount(['likedByProfiles', 'reposts'])
            ->withExists([
                'likedByProfiles as is_liked' => fn (Builder $query) => $query->whereKey($viewer?->id),
            ])
            ->latest('id')
            ->paginate(10);

        return inertia('Client/Profile/Show', [
            'profile' => ProfileResource::make($profile)->resolve(),
            'posts' => PostResource::collection($posts)->response()->getData(true),
        ]);
    }

    /**
     * Переключение подписки на профиль.
     *
     * Возвращает массив, а не Inertia-страницу: запрос уходит от axios, страница
     * остаётся на месте, обновить нужно только надпись на кнопке. Близнец
     * PostController::toggleLike().
     *
     * @return array<string, bool>
     */
    public function toggleSubscribe(Request $request, Profile $profile): array {
        $viewer = $request->user()->profile;

        // Подписка принадлежит профилю, и без профиля операция невозможна.
        abort_if($viewer === null, 403, 'У пользователя нет профиля.');

        // Подписка на себя запрещена на сервере, а не только скрытием кнопки:
        // can_subscribe в ресурсе — подсказка интерфейсу, POST-запрос руками
        // никто не отменял.
        abort_if($viewer->id === $profile->id, 403, 'Нельзя подписаться на себя.');

        // toggle() на связи «мои подписки»: строка в profile_subscriptions есть —
        // удалить, нет — вставить. Возвращает ['attached' => [...], 'detached' => [...]].
        $changes = $viewer->subscriptions()->toggle($profile->id);

        return [
            'is_subscribed' => $changes['attached'] !== [],
        ];
    }
}
