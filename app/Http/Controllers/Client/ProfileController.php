<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Post\PostResource;
use App\Http\Resources\Profile\ProfileResource;
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
            ->with(['author', 'category'])
            ->withCount('likedByProfiles')
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
}
