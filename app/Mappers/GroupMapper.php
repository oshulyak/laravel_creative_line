<?php

namespace App\Mappers;

use App\Http\Resources\Group\GroupResource;
use App\Http\Resources\Theme\ThemeResource;
use App\Models\Group;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Пропсы страниц групп: index() — Client/Group/Index, show() — Client/Group/Show.
 *
 * $viewer — профиль того, кто смотрит. Может быть null: смотреть группы
 * можно и без профиля, тогда is_subscribed просто придёт false.
 *
 * Методы статические: состояния и зависимостей у маппера нет, как у ChatMapper.
 */
class GroupMapper {
    /**
     * Каталог: все группы с числом участников и признаком «я в группе».
     *
     * Без пагинации — осознанное упрощение, как у списка чатов.
     *
     * @return array{groups: array<int, array<string, mixed>>}
     */
    public static function index(?Profile $viewer): array {
        $groups = Group::query()
            // Число участников — подзапрос COUNT в том же SELECT.
            ->withCount('subscribers')
            // «Состою ли я в группе» — подзапрос EXISTS, суженный до смотрящего.
            // Тот же приём, что is_liked у постов и is_subscribed у профиля.
            // Весь каталог — один запрос, сколько бы групп ни было.
            //
            // whereKey(null) у пользователя без профиля не совпадёт ни с одной строкой.
            ->withExists([
                'subscribers as is_subscribed' => fn (Builder $query) => $query->whereKey($viewer?->id),
            ])
            // Новые группы сверху. По id, а не по дате: id строго возрастает.
            ->latest('id')
            ->get();

        return [
            'groups' => GroupResource::collection($groups)->resolve(),
        ];
    }

    /**
     * Страница группы: сама группа и её темы.
     *
     * @return array{group: array<string, mixed>, themes: array<int, array<string, mixed>>}
     */
    public static function show(Group $group, ?Profile $viewer): array {
        // loadCount() и loadExists(), а не withCount(): модель уже получена
        // привязкой маршрута, достроить её запрос нельзя — догружаем
        // отдельными запросами, как в ProfileController::show().
        $group->loadCount('subscribers');
        $group->loadExists([
            'subscribers as is_subscribed' => fn (Builder $query) => $query->whereKey($viewer?->id),
        ]);

        $themes = $group->themes()
            // Ник автора нужен каждой строке списка. Без with() — N+1.
            ->with('author')
            // Сколько сообщений в теме — подзапросом, сами сообщения не грузим.
            ->withCount('messages')
            // Новые темы сверху.
            ->latest('id')
            ->get();

        return [
            'group' => GroupResource::make($group)->resolve(),
            'themes' => ThemeResource::collection($themes)->resolve(),
        ];
    }
}
