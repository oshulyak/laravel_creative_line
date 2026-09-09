<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Comment\StoreRequest;
use App\Http\Resources\Comment\CommentResource;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller {
    /**
     * Страница комментариев к посту.
     *
     * Возвращает массив, а не Inertia-страницу: за списком ходит axios, страница
     * поста при этом остаётся на месте. Форма ответа — та же, что у ленты и админского
     * списка: { data, links, meta }.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request, Post $post): array {
        $this->abortUnlessPostVisible($request, $post);

        $profileId = $request->user()->profile?->id;

        $comments = $post->comments()
            // В базе от сидера лежат и комментарии со статусом moderate. Клиент
            // видит только опубликованное — ровно как в ленте.
            ->where('status', Comment::STATUS_PUBLISHED)
            // Ник автора нужен каждой строке списка. Без with() десять комментариев
            // дали бы десять лишних запросов (N+1).
            ->with('author')
            ->withCount('likedByProfiles')
            // Тот же подзапрос, что у постов: «лайкнул ли ЭТОТ профиль».
            // whereKey(null) при отсутствии профиля даст сравнение с NULL,
            // не истинное ни для одной строки, — все комментарии приедут нелайкнутыми.
            ->withExists([
                'likedByProfiles as is_liked' => fn (Builder $query) => $query->whereKey($profileId),
            ])
            // Новые сверху. Сортировка по id, а не по published_at: id строго
            // возрастает и не бывает NULL, а даты у двух комментариев из одной
            // секунды совпадут, и offset начнёт путать страницы.
            ->latest('id')
            ->paginate(10);

        return CommentResource::collection($comments)->response()->getData(true);
    }

    /**
     * Добавление комментария к посту.
     *
     * Все поля, кроме content, подставил StoreRequest::prepareForValidation(),
     * а commentable_* поставит сама связь — поэтому в контроллере одна строка.
     */
    public function store(StoreRequest $request, Post $post): JsonResponse {
        $this->abortUnlessPostVisible($request, $post);

        // create() на связи morphMany сам заполняет commentable_id и commentable_type
        // из родителя. Post::class уедет в базу как строка — morphMap в проекте
        // не настроен, и это осознанно: карта алиасов нужна, когда классы переезжают.
        $comment = $post->comments()->create($request->validated());

        // Ник автора клиенту нужен сразу — комментарий появится в списке без перезагрузки.
        $comment->load('author');

        // А loadCount() и loadExists() здесь НЕ нужны, хотя список их отдаёт.
        // Ответ известен заранее: у только что созданного комментария ноль лайков
        // и он не лайкнут. Ключей likes_count и is_liked в ответе просто не будет
        // (whenCounted/whenHas их не найдут), а на клиенте сработают значения
        // по умолчанию у пропсов LikeButton — 0 и false. Два запроса в базу
        // ради заранее известного ответа делать незачем.

        // 201 Created — правильный код для «создал новую запись». Тело — сам
        // комментарий: клиенту нужно вставить его в список, и второй запрос
        // за только что отправленными данными был бы лишним.
        //
        // resolve(), а не response(): нам нужен плоский объект, без обёртки data.
        return response()->json(CommentResource::make($comment)->resolve(), 201);
    }

    /**
     * Переключение лайка на комментарии.
     *
     * Близнец PostController::toggleLike(). Дублирование десяти строк здесь —
     * осознанный выбор: общий полиморфный «лайкатор» потребовал бы карты морф-типов
     * и проверки «а эту сущность вообще можно лайкать».
     *
     * @return array<string, mixed>
     */
    public function toggleLike(Request $request, Comment $comment): array {
        $profile = $request->user()->profile;

        abort_if($profile === null, 403, 'У пользователя нет профиля.');

        $changes = $comment->likedByProfiles()->toggle($profile->id);

        return [
            'is_liked' => $changes['attached'] !== [],
            'likes_count' => $comment->likedByProfiles()->count(),
        ];
    }

    /**
     * Комментарии видно там же, где виден сам пост.
     *
     * Правило слово в слово повторяет PostController::show(): чужой неопубликованный
     * пост не показываем, свой — показываем. Без этой проверки комментарии к посту
     * на модерации читались бы прямым запросом к /posts/5/comments в обход страницы.
     *
     * Это уже ТРЕТЬЕ место в проекте, где записано «кому виден пост», и второе,
     * где записано «пост мой». Именно такое давление и приводит к политикам.
     */
    private function abortUnlessPostVisible(Request $request, Post $post): void {
        abort_unless(
            $post->status === Post::STATUS_PUBLISHED
                || $post->author_id === $request->user()->profile?->id,
            404,
        );
    }
}
