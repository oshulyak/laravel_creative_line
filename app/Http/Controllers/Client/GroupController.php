<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Group\StoreRequest as StoreGroupRequest;
use App\Http\Requests\Client\Theme\StoreRequest as StoreThemeRequest;
use App\Mappers\GroupMapper;
use App\Models\Group;
use App\Services\GroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class GroupController extends Controller {
    /**
     * Каталог групп.
     */
    public function index(Request $request): Response {
        return inertia('Client/Group/Index', GroupMapper::index($request->user()->profile));
    }

    /**
     * Страница группы.
     *
     * Проверки доступа нет — в отличие от ChatController::show(): группу
     * видят все вошедшие. Что можно только участнику, проверяют
     * Form Request темы и сообщения.
     */
    public function show(Request $request, Group $group): Response {
        return inertia('Client/Group/Show', GroupMapper::show($group, $request->user()->profile));
    }

    /**
     * Создание группы: создатель сразу становится участником (GroupService).
     *
     * Редирект, а не JSON: форму отправляет router.post(), и Inertia сама
     * откроет страницу новой группы — как после создания группового чата.
     */
    public function store(StoreGroupRequest $request): RedirectResponse {
        $group = GroupService::store($request->validated(), $request->user()->profile);

        // В route() передаём модель, а не $group->id: Laravel сам возьмёт ключ.
        return redirect()->route('client.groups.show', $group);
    }

    /**
     * Вступить в группу или выйти из неё.
     *
     * Массив, а не страница: кнопка отправляет запрос через axios и меняет
     * только свою надпись и счётчик. Близнец PostController::toggleLike().
     *
     * @return array{is_subscribed: bool, subscribers_count: int}
     */
    public function toggleSubscribe(Request $request, Group $group): array {
        $viewer = $request->user()->profile;

        // Участники группы — профили: без профиля вступать некому.
        abort_if($viewer === null, 403, 'У пользователя нет профиля.');

        // toggle() на связи «мои группы»: строка в group_profile есть —
        // удалить, нет — вставить. Возвращает ['attached' => [...], 'detached' => [...]].
        $changes = $viewer->groups()->toggle($group->id);

        return [
            'is_subscribed' => $changes['attached'] !== [],
            // Считаем после переключения: пока страница была открыта,
            // вступить мог кто-то ещё.
            'subscribers_count' => $group->subscribers()->count(),
        ];
    }

    /**
     * Создание темы в группе.
     *
     * Участие проверил StoreThemeRequest::authorize(): посторонний до этого
     * метода не дойдёт. Редирект — на страницу новой темы: форму отправляет
     * router.post().
     */
    public function storeTheme(StoreThemeRequest $request, Group $group): RedirectResponse {
        // create() на связи hasMany сам заполнит group_id,
        // author_id и title пришли из validated().
        $theme = $group->themes()->create($request->validated());

        return redirect()->route('client.themes.show', $theme);
    }
}
