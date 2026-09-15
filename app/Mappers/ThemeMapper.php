<?php

namespace App\Mappers;

use App\Http\Resources\Theme\ThemeResource;
use App\Http\Resources\ThemeMessage\ThemeMessageResource;
use App\Models\Profile;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Builder;

/**
 * Пропсы страницы темы: show() — Client/Theme/Show.
 */
class ThemeMapper {
    /**
     * Тема с автором и группой, сообщения темы.
     *
     * @return array{theme: array<string, mixed>, messages: array<int, array<string, mixed>>}
     */
    public static function show(Theme $theme, ?Profile $viewer): array {
        // Группа нужна шапке (ссылка назад, в группу) и форме: писать может
        // только участник.
        $theme->load(['author', 'group']);

        // is_subscribed — подсказка интерфейсу, показывать ли форму.
        // Настоящая проверка — ThemeMessage\StoreRequest::authorize().
        $theme->group->loadExists([
            'subscribers as is_subscribed' => fn (Builder $query) => $query->whereKey($viewer?->id),
        ]);

        // Все сообщения без пагинации — как в ChatMapper::show().
        $messages = $theme->messages()
            // Ник автора нужен каждому сообщению. Без with() — N+1.
            ->with('author')
            // Старые сверху, новые снизу. По id: у сообщений из одной секунды
            // даты совпадут, а id строго возрастает.
            ->oldest('id')
            ->get();

        return [
            'theme' => ThemeResource::make($theme)->resolve(),
            'messages' => ThemeMessageResource::collection($messages)->resolve(),
        ];
    }
}
