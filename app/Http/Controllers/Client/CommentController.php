<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Comment\StoreRequest;
use App\Http\Resources\Comment\CommentResource;
use App\Mail\Comment\StoreCommentMail;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Mail;

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
            ->withCount([
                'likedByProfiles',
                // Псевдоним + условие. Связь называется comments, а клиенту нужен
                // ключ replies_count — «as replies_count» переименовывает результат.
                //
                // Условие обязательно: считать надо ровно то, что потом покажем.
                // Без него кнопка обещала бы «Показать ответы (3)», а разворачивала
                // один: ответы на модерации в ветку не попадут.
                'comments as replies_count' => fn (Builder $query) => $query
                    ->where('status', Comment::STATUS_PUBLISHED),
            ])
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

        // Уведомление автору публикации.
        //
        // Условие — «комментатор и автор не один человек»: писать себе о собственном
        // комментарии незачем, а на странице поста автор комментирует свой пост чаще
        // всех остальных вместе взятых.
        //
        // Mail::to() ждёт объект с полями email и name — это User, а не Profile:
        // почта лежит в users, у профиля её нет вовсе. Отсюда цепочка author->user.
        //
        // send() — отправка прямо здесь и сейчас: строка вернёт управление только
        // после того, как SMTP-сервер примет письмо. Пока ждём его, ждёт и пользователь,
        // а упавший SMTP уронит запрос уже ПОСЛЕ записи комментария в базу.
        // Это осознанный промежуточный шаг: в 29-м уроке отправка уедет в очередь.
        //
        // Почему прямо в контроллере, а не в событии со слушателем: следствие у действия
        // пока одно. Событие CommentCreated окупится, когда их станет два-три.

        // if ($post->author_id !== $comment->author_id) {
        Mail::to($post->author->user)->send(new StoreCommentMail($post, $comment));
        // }

        // 201 Created — правильный код для «создал новую запись». Тело — сам
        // комментарий: клиенту нужно вставить его в список, и второй запрос
        // за только что отправленными данными был бы лишним.
        //
        // resolve(), а не response(): нам нужен плоский объект, без обёртки data.
        return response()->json(CommentResource::make($comment)->resolve(), 201);
    }

    /**
     * Ответы на комментарий.
     *
     * Возвращает ВСЮ ветку целиком, без пагинации, — и это осознанный выбор,
     * а не забывчивость. Ветку открывают по кнопке, её размер известен заранее
     * (replies_count уже приехал вместе с комментарием), и в один уровень она
     * короткая. Бесконечная загрузка внутри бесконечной загрузки усложнила бы
     * компонент вдвое ради случая, которого в проекте пока нет.
     *
     * Порядок обратный списку комментариев: oldest(), а не latest(). Ветка
     * читается как диалог — сверху реплика, ниже ответы в порядке появления.
     */
    public function replies(Request $request, Comment $comment): AnonymousResourceCollection {
        $this->abortUnlessPostVisible($request, $this->postOfComment($comment));

        $profileId = $request->user()->profile?->id;

        $replies = $comment->comments()
            ->where('status', Comment::STATUS_PUBLISHED)
            ->with('author')
            ->withCount('likedByProfiles')
            // Лайки в ветке работают так же, как в списке, — значит и подзапрос
            // «лайкнул ли этот профиль» нужен тот же. Без него все ответы
            // приедут нелайкнутыми, и сердечко будет «забывать» состояние.
            ->withExists([
                'likedByProfiles as is_liked' => fn (Builder $query) => $query->whereKey($profileId),
            ])
            ->oldest('id')
            ->get();

        // Без ->response()->getData(true): meta и links тут взяться неоткуда,
        // пагинации нет. Клиент получит { "data": [...] } — обёртку data
        // добавляет сама коллекция ресурса.
        return CommentResource::collection($replies);
    }

    /**
     * Добавление ответа на комментарий.
     *
     * Запрос тот же самый, что у комментария к посту: форма присылает один
     * content, author_id и status подставляет prepareForValidation(). Контракт
     * формы не зависит от того, кто родитель, — значит и FormRequest один.
     *
     * Родителя, как и в store(), проставит связь: $comment->comments()->create()
     * запишет в commentable_type класс Comment вместо Post.
     */
    public function storeReply(StoreRequest $request, Comment $comment): JsonResponse {
        $this->abortUnlessPostVisible($request, $this->postOfComment($comment));

        $reply = $comment->comments()->create($request->validated());

        $reply->load('author');

        return response()->json(CommentResource::make($reply)->resolve(), 201);
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

    /**
     * Пост, которому принадлежит комментарий, — и одновременно проверка
     * «это комментарий к посту, а не ответ».
     *
     * Оба метода ветки (replies и storeReply) начинаются с него, и оба правила
     * выполняются здесь разом:
     *
     * 1. Один уровень. У ответа commentable — это Comment, instanceof Post ложен,
     *    и запрос отваливается 404. Ответить на ответ нельзя даже curl-ом.
     * 2. Видимость. Ветка видна там же, где виден пост: полученный пост уходит
     *    в тот же abortUnlessPostVisible(), что и в index()/store().
     *
     * Почему 404, а не 422: снаружи это выглядит как «такого адреса нет» —
     * у ответа ветки не существует. 422 сообщал бы, что адрес правильный,
     * а данные плохие, но данные тут ни при чём.
     */
    private function postOfComment(Comment $comment): Post {
        $post = $comment->commentable;

        abort_unless($post instanceof Post, 404);

        return $post;
    }
}
