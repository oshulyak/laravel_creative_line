<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Post\PostResource;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Response;

class FeedController extends Controller {
    /**
     * Лента публикаций.
     *
     * Подписок в схеме пока нет, поэтому лента показывает все опубликованные посты.
     * Когда появятся подписки, изменится только условие отбора — остальное останется.
     *
     * В отличие от админского index(), здесь одна ветка ответа: страница всегда приезжает
     * через Inertia. Фильтра нет, запросов от axios нет — значит и wantsJson() не нужен.
     */
    public function index(Request $request): Response {
        // Лайк принадлежит профилю, а не пользователю (likeables.profile_id → profiles.id),
        // поэтому везде ниже работаем с id профиля.
        $profileId = $request->user()->profile?->id;

        $posts = Post::query()
            // Клиент видит только опубликованное. Посты на модерации — забота админки;
            // своё «на модерации» автор увидит на странице «Мои публикации».
            ->where('status', Post::STATUS_PUBLISHED)
            // author и category грузим заранее — иначе на десять карточек ленты
            // получим двадцать лишних запросов (N+1).
            //
            // parent.author — вложенная связь: «загрузи родителя, а у родителя —
            // автора». Это два запроса на весь список, а не на каждую карточку;
            // у обычных постов parent вернётся null.
            ->with(['author', 'category', 'parent.author'])
            // Счётчики одним подзапросом на каждый. Атрибуты приедут как
            // liked_by_profiles_count и reposts_count, наружу PostResource отдаст
            // их как likes_count и reposts_count.
            ->withCount(['likedByProfiles', 'reposts'])
            // withExists — брат withCount: тот считает связанные записи, этот отвечает,
            // есть ли хоть одна. Замыкание сужает подзапрос до текущего профиля, иначе
            // вопрос звучал бы как «лайкнул ли пост хоть кто-нибудь».
            //
            // Алиас as is_liked задаёт имя атрибута: без него он звался бы
            // liked_by_profiles_exists, а наружу мы отдаём короткое is_liked.
            //
            // Профиля может не быть: whereKey(null) даст сравнение с NULL, которое
            // не истинно ни для одной строки, — и все посты приедут нелайкнутыми.
            ->withExists([
                'likedByProfiles as is_liked' => fn (Builder $query) => $query->whereKey($profileId),
            ])
            // NULLS LAST, а не просто latest('published_at'): в PostgreSQL сортировка
            // по убыванию по умолчанию ставит NULL первыми, и посты без даты публикации
            // оказались бы вверху ленты. В базе такие есть.
            //
            // orderByRaw — сырой фрагмент SQL, синтаксис специфичен для PostgreSQL;
            // пользовательских данных в строке нет, подставлять нечего.
            ->orderByRaw('published_at DESC NULLS LAST')
            // Второй ключ обязателен: published_at может совпасть у постов, созданных
            // в одну секунду, а offset без строгого порядка позволяет одной записи
            // приехать сразу на двух страницах.
            ->latest('id')
            ->paginate(10);

        return inertia('Client/Feed/Index', [
            // Та же упаковка, что в админке: { data, links, meta }. Форма ответа
            // у пагинации одна на всё приложение, и Vue-страницы читают её одинаково.
            'posts' => PostResource::collection($posts)->response()->getData(true),
        ]);
    }
}
