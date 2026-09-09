# Lesson 25 - Комментарии: форма, лайки и бесконечная загрузка

Цель урока: оживить нижнюю половину страницы поста. Сейчас там пусто — комментарии в базе есть (их насыпал сидер), но клиент про них не знает. К концу урока под постом появится список комментариев, форма отправки нового, лайк на каждом комментарии и подгрузка следующих страниц по мере прокрутки.

По пути разбираются: **FormRequest для веб-формы** и почему в нём нельзя принимать `author_id` от клиента; `prepareForValidation()` как штатное место для серверных значений; ресурс, унаследованный от API-урока, и что в нём протухло; `casts()` и формат даты на границе PHP → JSON; ещё один общий компонент (`LikeButton`) и признак, по которому пора выносить; **`IntersectionObserver`** и хуки жизненного цикла `mounted`/`beforeUnmount`; и главный сюжет — чем бесконечная загрузка отличается от обычной пагинации и почему граница страницы умеет «съезжать».

Отправная точка — состояние после 24-го урока: карточка поста вынесена в `ItemPost.vue`, кнопка удаления — в `DeletePost.vue`, лайк поста работает и в ленте, и на странице поста.

## 1. Карта урока

Что появляется:

| Файл | Роль |
| --- | --- |
| `app/Http/Controllers/Client/CommentController.php` | список, создание и лайк комментария |
| `app/Http/Requests/Client/Comment/StoreRequest.php` | валидация формы комментария |
| `resources/js/Components/LikeButton.vue` | кнопка лайка — одна на пост и на комментарий |
| `resources/js/Components/Comment/CommentList.vue` | список с бесконечной загрузкой |
| `resources/js/Components/Comment/ItemComment.vue` | один комментарий |
| `resources/js/Components/Comment/CommentForm.vue` | форма добавления |
| `tests/Feature/ClientCommentTest.php` | тесты на список, создание, валидацию и лайк |

Что правится:

| Файл | Что меняется |
| --- | --- |
| `routes/client.php` | три маршрута: список, создание, лайк комментария |
| `app/Models/Comment.php` | `casts()` для `published_at` |
| `app/Http/Resources/Comment/CommentResource.php` | убираем `parent_id`, добавляем автора и лайки |
| `resources/js/Pages/Client/Post/Show.vue` | подключается `CommentList`, лайк уезжает в `LikeButton` |
| `resources/js/Components/Post/ItemPost.vue` | лайк уезжает в `LikeButton` |

Команды:

```shell
php artisan make:controller Client/CommentController
php artisan make:request Client/Comment/StoreRequest
php artisan make:test --phpunit ClientCommentTest
```

Папки `app/Http/Requests/Client/Comment/` и `resources/js/Components/Comment/` — новые. Соглашение то же, что в 24-м уроке: у клиентской части свой неймспейс рядом с `Admin` и `Api`, а Vue-компоненты, привязанные к сущности, лежат в папке с именем сущности.

## 2. Что уже есть и чего не хватает

Стоит свериться с базой до кода — половина урока опирается на то, что уже написано.

**Таблица `comments`** (миграция 28.05): `morphs('commentable')` вместо прежней пары `post_id` + `parent_id`, `author_id` → `profiles.id`, `content`, `status`, `published_at`. То есть комментарий может висеть и на посте, и на другом комментарии — ветки схема поддерживает. В этом уроке мы их не делаем: показываем плоский список комментариев к посту, ответы оставляем на потом.

**Модель `Comment`** уже знает всё нужное: `commentable()`, `author()`, `likedByProfiles()`. Ничего дописывать не придётся, кроме `casts()`.

**Лайки** — та же полиморфная связь `likeables`, что у постов. `Comment::likedByProfiles()` уже объявлена, и `toggle()` на ней работает ровно так же, как на посте. Серверная часть лайка комментария — это десять строк по образцу `PostController::toggleLike()`.

**Статусы.** Здесь ловушка на ровном месте: у `Post` статусы — числа (`STATUS_PUBLISHED = 1`), у `Comment` — строки (`STATUS_PUBLISHED = 'published'`). Так сложилось исторически, и пока это просто нужно помнить: сравнение `$comment->status === 1` не сработает никогда.

Чего нет вовсе — клиентских маршрутов, контроллера и разметки. Их и пишем.

## 3. Маршруты

IN `routes/client.php` — внутри той же группы `auth`:
```php
    // Список комментариев поста. Отдельный маршрут, а не проп страницы: комментарии
    // догружаются порциями уже после того, как пост показан, и ходить за ними будет
    // axios, а не Inertia. Заодно страница поста не меняется вовсе.
    //
    // Адрес вложенный — posts/{post}/comments: комментарий не существует сам по себе,
    // он всегда чей-то. Это стандартная форма вложенного ресурса в REST.
    Route::get('posts/{post}/comments', [CommentController::class, 'index'])
        ->whereNumber('post')
        ->name('client.posts.comments.index');

    // Тот же адрес, другой глагол: GET читает список, POST добавляет в него запись.
    Route::post('posts/{post}/comments', [CommentController::class, 'store'])
        ->whereNumber('post')
        ->name('client.posts.comments.store');

    // Лайк комментария — близнец client.posts.likes.toggle. Адрес НЕ вложен в пост:
    // у комментария есть собственный id, и знать его родителя, чтобы поставить лайк,
    // не нужно. Вложенность в URL оправдана там, где без родителя не найти ребёнка.
    Route::post('comments/{comment}/likes', [CommentController::class, 'toggleLike'])
        ->whereNumber('comment')
        ->name('client.comments.likes.toggle');
```

Плюс импорт `use App\Http\Controllers\Client\CommentController;`.

## 4. `CommentResource` и дата комментария

`CommentResource` в проекте уже есть — его сделали в API-уроке, и с тех пор схема ушла вперёд. Сейчас он отдаёт `parent_id`, колонки с таким именем в таблице больше нет: её заменил полиморфный `commentable`. Ключ приезжает клиенту как `null` и молча вводит в заблуждение — это как раз тот случай, когда ресурс нужно править, а не обходить.

IN `app/Http/Resources/Comment/CommentResource.php`:
```php
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'author_id' => $this->author_id,
            // parent_id заменён парой commentable_*: колонки parent_id в таблице нет
            // с тех пор, как ветку ответов и привязку к посту слили в один morphs().
            'commentable_id' => $this->commentable_id,
            'commentable_type' => $this->commentable_type,
            'content' => $this->content,
            'status' => $this->status,
            'published_at' => $this->published_at,
            // Дальше — ровно те же три ключа, что у PostResource, и по тем же причинам.
            // Форма ответа для лайкаемой сущности получается одинаковой, и клиентская
            // кнопка лайка сможет работать и с постом, и с комментарием (п. 7).
            'likes_count' => $this->whenCounted('likedByProfiles'),
            'is_liked' => $this->whenHas('is_liked', fn (mixed $value): bool => (bool) $value),
            'author' => $this->whenLoaded(
                'author',
                fn (Profile $author): array => ProfileResource::make($author)->resolve(),
            ),
        ];
    }
```

Импорты: `use App\Http\Resources\Profile\ProfileResource;` и `use App\Models\Profile;`.

Ресурс общий с `Api\CommentController` — там он тоже используется. Ничего не сломается: все три новых ключа условные (`whenCounted`/`whenHas`/`whenLoaded`), и в API-ответе их просто не будет, потому что API не грузит ни автора, ни счётчики.

**Дата.** У `Comment` нет `casts()`, поэтому `published_at` приезжает из PostgreSQL строкой вида `2026-09-08 10:00:00`. Такую строку `new Date()` в JS разбирает по-разному в разных браузерах — Safari на ней спотыкается. Лечится одной строкой в модели.

IN `app/Models/Comment.php` — рядом с `$fillable`:
```php
    /**
     * published_at — не строка, а момент времени. Каст превращает его в Carbon
     * при чтении и обратно при записи, а в JSON он уходит в ISO-8601
     * (2026-09-08T10:00:00.000000Z) — единственный формат, который одинаково
     * разбирают все браузеры.
     *
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'published_at' => 'datetime',
        ];
    }
```

Побочный эффект: формат даты изменится и в API-ответе. Это улучшение, а не регресс, — но знать про него нужно.

## 5. `Client\Comment\StoreRequest` — что клиенту можно присылать

Ключевой вопрос урока на серверной стороне. В форме комментария ровно одно поле — текст. А в таблице колонок пять. Откуда берутся остальные четыре?

`commentable_id` и `commentable_type` придут из URL: маршрут вложенный, пост известен. Остальные три — `author_id`, `status`, `published_at` — **не должны приходить от клиента вообще**. Автор комментария определяется сессией, а не полем формы: иначе достаточно подставить чужой `author_id` в запрос, и комментарий подпишется чужим ником.

Сравните с `Api\Comment\StoreRequest`, который в проекте уже лежит: там `author_id` и `status` — обязательные поля запроса. Это не ошибка, это другой контракт: API-клиент был доверенным и сам решал, от чьего имени пишет. У формы в браузере такого доверия нет, поэтому и запрос свой.

Место для серверных значений — `prepareForValidation()`. Тот же приём, что в `Admin\Post\StoreRequest`.

```shell
php artisan make:request Client/Comment/StoreRequest
```

IN `app/Http/Requests/Client/Comment/StoreRequest.php`:
```php
<?php

namespace App\Http\Requests\Client\Comment;

use App\Models\Comment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest {
    /**
     * Генератор ставит здесь false — это топ-1 причина внезапного 403 при отправке формы.
     * Проверка «а может ли этот пользователь комментировать» появится вместе с политиками.
     */
    public function authorize(): bool {
        return true;
    }

    /**
     * Из формы приходит только content. Остальные три ключа подставляет
     * prepareForValidation(), но правила у них такие же настоящие: значение
     * от сервера тоже стоит проверить — опечатка в коде так поймается на 422,
     * а не на 500 от PostgreSQL.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // Колонка content — text, у неё нет ограничения длины. 2000 символов —
            // продуктовое решение, а не отражение схемы: комментарий длиной с роман
            // никому не нужен, а поле без верхней границы — это открытая дверь.
            'content' => ['required', 'string', 'max:2000'],
            // exists нужен даже при внешнем ключе: без него несуществующий id
            // дойдёт до INSERT и станет 500-й вместо 422 с внятным сообщением.
            'author_id' => ['required', 'integer', 'exists:profiles,id'],
            'status' => ['required', 'string', Rule::in(array_keys(Comment::getStatuses()))],
            'published_at' => ['required', 'date'],
        ];
    }

    /**
     * Подмешиваем поля, которых нет в форме, до запуска валидации.
     *
     * Порядок работы FormRequest: authorize() → prepareForValidation() → rules() →
     * validated(). Добавленные здесь ключи уже существуют к моменту проверки
     * и попадают в validated() — а значит, доедут до create() в контроллере.
     *
     * Комментарий принадлежит профилю, а не пользователю: comments.author_id
     * ссылается на profiles.id. Отсюда ->profile->id.
     *
     * Оба ?-> страхуют цепочку. Если профиля нет, author_id станет null,
     * правило required вернёт 422 — контроллеру не придётся писать abort_if().
     *
     * Комментарий публикуется сразу: модерация комментариев в проекте пока
     * не заведена, а «отправил и ничего не появилось» — худшее, что можно
     * показать пользователю без объяснений.
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'author_id' => $this->user()?->profile?->id,
            'status' => Comment::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }
}
```

Заметьте, чего в `merge()` нет: `commentable_id` и `commentable_type`. Их подставит сама связь — `$post->comments()->create(...)` знает и id родителя, и его класс. Дублировать это в запросе значило бы завести второй источник правды.

## 6. `Client\CommentController`

```shell
php artisan make:controller Client/CommentController
```

IN `app/Http/Controllers/Client/CommentController.php`:
```php
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
     * и проверки «а эту сущность вообще можно лайкать». Разбор — в п. 16.
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
     * где записано «пост мой». Именно такое давление и приводит к политикам —
     * см. п. 16.
     */
    private function abortUnlessPostVisible(Request $request, Post $post): void {
        abort_unless(
            $post->status === Post::STATUS_PUBLISHED
                || $post->author_id === $request->user()->profile?->id,
            404,
        );
    }
}
```

## 7. `LikeButton.vue` — третья копия и повод её убрать

Логика лайка сейчас написана дважды: в `ItemPost.vue` и в `Show.vue`. Комментарий стал бы третьим местом. Правило простое: **два одинаковых куска — терпимо, три — пора выносить**. В 24-м уроке это уже стояло в списке «что можно сделать лучше»; сейчас для этого появился повод.

Главный вопрос при выносе — что компонент должен знать. Соблазн передать ему `post` или `comment` и пусть сам решает, куда стучаться. Так делать нельзя: компонент немедленно узнает про предметную область, и для комментария придётся дописывать в него ветку `if`. Поэтому наружу отдаём **готовый URL**: кнопка знает, что нужно сходить по адресу и получить `{ is_liked, likes_count }`, и больше ничего.

IN `resources/js/Components/LikeButton.vue`:
```vue
<template>
    <button
        type="button"
        :disabled="isPending"
        :aria-pressed="isLiked"
        class="inline-flex items-center gap-2 disabled:opacity-50"
        :class="isLiked ? 'text-rose-600' : 'text-gray-400 hover:text-rose-500'"
        @click="toggle"
    >
        <!--
            Одна иконка на оба состояния: заливка переключается атрибутом fill.
            currentColor означает «цвет текста кнопки» — цвет задаёт класс выше.
        -->
        <svg
            :class="iconClass"
            viewBox="0 0 24 24"
            :fill="isLiked ? 'currentColor' : 'none'"
            stroke="currentColor"
            stroke-width="1.5"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"
            />
        </svg>

        <span>{{ count }}</span>
    </button>
</template>

<script>
import axios from 'axios';

export default {
    name: 'LikeButton',
    props: {
        // Готовый адрес, а не id + имя маршрута: компонент не знает и не должен
        // знать, лайкают через него пост или комментарий. Собрать URL — забота
        // того, кто компонент ставит.
        url: {
            type: String,
            required: true,
        },
        // Начальные значения, дальше кнопка ведёт их сама. Приставка initial —
        // конвенция Vue для «пропса, с которого начинается локальное состояние»:
        // она подсказывает читателю, что дальше значение живёт своей жизнью.
        //
        // default-ы не декоративные: у только что созданного комментария сервер
        // вообще не отдаёт ключей likes_count и is_liked (п. 6), и в компонент
        // приедет undefined. Vue в таком случае подставит значение по умолчанию.
        initialLiked: {
            type: Boolean,
            default: false,
        },
        initialCount: {
            type: Number,
            default: 0,
        },
        // Размер иконки задаёт родитель: в карточке поста она крупнее, чем
        // под комментарием. Класс, а не число, — чтобы не изобретать свою
        // систему размеров поверх Tailwind.
        iconClass: {
            type: String,
            default: 'h-5 w-5',
        },
    },
    // Сообщаем наружу, чем закончилось переключение. Никому из нынешних
    // родителей это не нужно, но контракт стоит копейку, а страница поста,
    // где счётчик мог бы дублироваться в шапке, появится завтра.
    emits: ['toggled'],
    data() {
        return {
            isLiked: this.initialLiked,
            count: this.initialCount,
            // Блокировка кнопки на время запроса — не косметика: в likeables стоит
            // unique(profile_id, likeable_type, likeable_id), и два быстрых клика
            // могут разойтись в гонке и уронить вставку нарушением уникальности.
            isPending: false,
        };
    },
    watch: {
        // Те же наблюдатели, что в ItemPost, и по той же причине: data() выполняется
        // один раз, а при частичной перезагрузке списка Vue переиспользует
        // существующие экземпляры — без наблюдателя в кнопке остались бы
        // счётчики с первой загрузки страницы.
        initialLiked(value) {
            this.isLiked = value;
        },
        initialCount(value) {
            this.count = value;
        },
    },
    methods: {
        toggle() {
            this.isPending = true;

            // Тела у запроса нет — второй аргумент axios.post() не нужен вовсе.
            // CSRF-заголовок axios подставит сам из куки XSRF-TOKEN.
            axios
                .post(this.url)
                .then((res) => {
                    // Оба значения берём из ответа, а не считаем на клиенте: пока
                    // страница была открыта, лайкнуть мог кто-то ещё, и локальный ++
                    // разошёлся бы с базой. Сервер — единственный источник правды.
                    this.isLiked = res.data.is_liked;
                    this.count = res.data.likes_count;

                    this.$emit('toggled', res.data);
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isPending = false;
                });
        },
    },
};
</script>
```

Компонент лежит в корне `Components/`, а не в `Components/Post/`: он больше не про пост. Это и есть проверка на переиспользуемость из 24-го урока — «знает ли компонент, где его показывают».

Теперь его нужно подставить в оба старых места.

IN `resources/js/Components/Post/ItemPost.vue` — весь блок `<button>` с сердечком в `<footer>` заменяется на:
```vue
            <LikeButton
                :url="route('client.posts.likes.toggle', postData.id)"
                :initial-liked="postData.is_liked"
                :initial-count="postData.likes_count"
            />
```

Из скрипта уезжают `import axios`, метод `toggleLike()` и поле `isLikePending`; в `components` добавляется `LikeButton`. Локальная копия `postData` при этом остаётся: её всё ещё читает разметка и правит наблюдатель.

IN `resources/js/Pages/Client/Post/Show.vue` — то же самое, только иконка крупнее:
```vue
        <LikeButton
            :url="route('client.posts.likes.toggle', post.id)"
            :initial-liked="post.is_liked"
            :initial-count="post.likes_count"
            icon-class="h-6 w-6"
            class="mt-6 text-sm"
        />
```

Здесь `postData` больше не нужен вовсе: локальная копия заводилась только ради лайка, а теперь состояние живёт внутри кнопки. Страница возвращается к чтению пропса `post` напрямую — из `data()`, `watch` и `methods` там не остаётся ничего.

Обратите внимание на `class="mt-6 text-sm"` на компоненте: это fallthrough-атрибут из 24-го урока — Vue сам перенесёт его на корневой `<button>` и **добавит** к классам, объявленным внутри, а не заменит их.

## 8. `ItemComment.vue` — один комментарий

IN `resources/js/Components/Comment/ItemComment.vue`:
```vue
<template>
    <article class="border-t border-gray-100 py-3">
        <p class="text-xs text-gray-400">
            <!--
                ?. и ?? — та же страховка, что в карточке поста: author приходит
                из whenLoaded(), и при незагруженной связи ключа в пропсах нет.
            -->
            {{ comment.author?.nickname ?? 'Аноним' }} · {{ publishedAt }}
        </p>

        <!-- whitespace-pre-line сохраняет переносы строк, набранные в textarea. -->
        <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ comment.content }}</p>

        <LikeButton
            :url="route('client.comments.likes.toggle', comment.id)"
            :initial-liked="comment.is_liked"
            :initial-count="comment.likes_count"
            icon-class="h-4 w-4"
            class="mt-2 text-xs"
        />
    </article>
</template>

<script>
import LikeButton from '@/Components/LikeButton.vue';

export default {
    name: 'ItemComment',
    components: { LikeButton },
    props: {
        comment: {
            type: Object,
            required: true,
        },
    },
    computed: {
        /**
         * Дата в человеческом виде.
         *
         * computed, а не method: значение зависит только от пропса, и Vue закеширует
         * результат до его изменения. Метод пересчитывался бы на каждый рендер списка.
         *
         * Форматируем на клиенте, а не на сервере: браузер знает часовой пояс
         * пользователя, PHP — нет. С сервера дата приезжает в UTC и в ISO-8601
         * (это дал каст в модели, п. 4), и toLocaleString переводит её в местное время.
         */
        publishedAt() {
            if (!this.comment.published_at) {
                return '';
            }

            return new Date(this.comment.published_at).toLocaleString('ru-RU', {
                dateStyle: 'long',
                timeStyle: 'short',
            });
        },
    },
};
</script>
```

Компонент получился без единого `data()` — он только рисует то, что дали. Такие компоненты самые надёжные: сломать в них нечего.

## 9. `CommentForm.vue` — форма добавления

IN `resources/js/Components/Comment/CommentForm.vue`:
```vue
<template>
    <!--
        form + @submit.prevent, а не просто кнопка: так работает отправка
        по Ctrl+Enter и по Enter в других полях, а браузер понимает, что это форма.
        .prevent отменяет штатную перезагрузку страницы — отправляем через axios.
    -->
    <form class="mb-4" @submit.prevent="submit">
        <textarea
            v-model="content"
            rows="3"
            maxlength="2000"
            placeholder="Написать комментарий…"
            class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
            :disabled="isSending"
        />

        <!--
            Ошибку показываем ту, что вернул сервер: клиентская проверка «поле
            не пустое» — это удобство, а не правило. Правило живёт в StoreRequest,
            и оно одно на все способы отправки.
        -->
        <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>

        <div class="mt-2 flex items-center justify-between">
            <span class="text-xs text-gray-400">{{ content.length }} / 2000</span>

            <button
                type="submit"
                :disabled="isSending || !content.trim()"
                class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
            >
                {{ isSending ? 'Отправляю…' : 'Отправить' }}
            </button>
        </div>
    </form>
</template>

<script>
import axios from 'axios';

export default {
    name: 'CommentForm',
    props: {
        postId: {
            type: Number,
            required: true,
        },
    },
    // Компонент сообщает, ЧТО произошло, и не решает, что с этим делать:
    // вставить комментарий в список — забота списка (тот же принцип, что
    // у DeletePost в 24-м уроке).
    emits: ['created'],
    data() {
        return {
            content: '',
            error: '',
            isSending: false,
        };
    },
    methods: {
        submit() {
            this.isSending = true;
            // Старую ошибку убираем до запроса, иначе она провисит до ответа
            // и будет выглядеть как реакция на новую отправку.
            this.error = '';

            axios
                .post(route('client.posts.comments.store', this.postId), {
                    content: this.content,
                })
                .then((res) => {
                    // Поле чистим только после успеха: при ошибке текст должен
                    // остаться, иначе пользователь потеряет написанное.
                    this.content = '';
                    // В res.data приехал готовый комментарий с автором — ровно
                    // в том виде, в каком его отдаёт список (п. 6).
                    this.$emit('created', res.data);
                })
                .catch((e) => {
                    // 422 от валидации приходит в форме { message, errors: { content: [...] } }.
                    // Берём первое сообщение по полю; если структура другая (500, обрыв
                    // сети) — показываем общий текст, а не «undefined».
                    this.error = e.response?.data?.errors?.content?.[0]
                        ?? 'Не удалось отправить комментарий.';
                })
                .finally(() => {
                    this.isSending = false;
                });
        },
    },
};
</script>
```

Почему axios, а не `useForm` из Inertia: форма живёт внутри списка, который и так грузится по axios, и отправка не должна приводить к переходу по маршруту. `useForm` вернул бы Inertia-ответ и перерисовал страницу — а нам нужно вставить одну запись в уже загруженный список, не трогая остального.

## 10. `CommentList.vue` — бесконечная загрузка

Самый содержательный компонент урока. Он держит массив комментариев, знает, какая страница следующая, и подгружает её, когда «дно» списка появляется в области просмотра.

IN `resources/js/Components/Comment/CommentList.vue`:
```vue
<template>
    <section class="mt-6 rounded-lg bg-white p-6 shadow">
        <h2 class="mb-4 text-lg font-semibold text-gray-900">
            Комментарии <span class="text-gray-400">{{ total }}</span>
        </h2>

        <CommentForm :post-id="postId" @created="handleCreated" />

        <ItemComment
            v-for="comment in comments"
            :key="comment.id"
            :comment="comment"
        />

        <!--
            Элемент-триггер. Он ВСЕГДА в разметке, даже когда грузить больше нечего:
            за ним следит IntersectionObserver, а наблюдать за узлом, который
            v-if то создаёт, то удаляет, пришлось бы переподключаясь.

            Он же кнопка. Наблюдатель срабатывает на ПЕРЕСЕЧЕНИЕ границы: если после
            подгрузки триггер так и остался на экране, второго срабатывания не будет,
            пока пользователь не прокрутит. Кнопка — честный запасной вариант,
            и она же делает загрузку доступной с клавиатуры.
        -->
        <div ref="trigger" class="py-4 text-center text-sm text-gray-400">
            <span v-if="isLoading">Загружаю…</span>
            <button
                v-else-if="hasMore"
                type="button"
                class="hover:text-sky-700"
                @click="loadMore"
            >
                Загрузить ещё
            </button>
            <span v-else-if="comments.length">Это все комментарии</span>
            <span v-else>Комментариев пока нет</span>
        </div>
    </section>
</template>

<script>
import axios from 'axios';
import CommentForm from '@/Components/Comment/CommentForm.vue';
import ItemComment from '@/Components/Comment/ItemComment.vue';

export default {
    name: 'CommentList',
    components: { CommentForm, ItemComment },
    props: {
        // Только id, а не весь пост: списку от поста больше ничего не нужно,
        // и узкий контракт делает компонент проще для чтения.
        postId: {
            type: Number,
            required: true,
        },
    },
    data() {
        return {
            comments: [],
            total: 0,
            nextPage: 1,
            // null — «ещё ни разу не спрашивали». Отличать это состояние от «страниц
            // больше нет» обязательно: иначе при первом рендере hasMore будет false
            // и первая загрузка не начнётся.
            lastPage: null,
            isLoading: false,
        };
    },
    computed: {
        hasMore() {
            return this.lastPage === null || this.nextPage <= this.lastPage;
        },
    },
    /**
     * mounted — хук жизненного цикла: компонент уже создан И вставлен в DOM.
     * Оба условия важны: до вставки this.$refs.trigger ещё не существует.
     */
    mounted() {
        this.loadMore();

        // IntersectionObserver — браузерный API: он сам следит, попал ли элемент
        // в область просмотра, и зовёт колбэк. Альтернатива — обработчик на scroll
        // с ручным счётом координат: он выполняется на каждый пиксель прокрутки
        // и нагружает главный поток, тогда как наблюдатель работает вне его.
        //
        // this.observer намеренно НЕ объявлен в data(): всё, что там объявлено,
        // Vue делает реактивным и оборачивает в Proxy, а наблюдателю это не нужно —
        // разметка от него не зависит. Обычное поле на экземпляре компонента дешевле
        // и честнее описывает намерение.
        this.observer = new IntersectionObserver(
            (entries) => {
                if (entries[0].isIntersecting) {
                    this.loadMore();
                }
            },
            // rootMargin расширяет область наблюдения на 200px вниз: загрузка
            // стартует до того, как пользователь упрётся в конец списка,
            // и подгрузка успевает произойти незаметно.
            { rootMargin: '200px' },
        );

        this.observer.observe(this.$refs.trigger);
    },
    /**
     * Зеркальный хук: компонент вот-вот исчезнет.
     *
     * disconnect() обязателен. Наблюдатель — объект браузера, он держит ссылку
     * и на DOM-узел, и на колбэк, а колбэк замыкает this. При Inertia-переходе
     * на другую страницу компонент уничтожается, но наблюдатель без disconnect()
     * остаётся жив вместе со всем, на что ссылается, — это утечка памяти.
     */
    beforeUnmount() {
        this.observer?.disconnect();
    },
    methods: {
        loadMore() {
            // Две причины выйти сразу. Первая: запрос уже в пути — наблюдатель
            // легко срабатывает одновременно с вызовом из mounted(), и без этой
            // проверки первая страница приехала бы дважды. Вторая: страниц больше нет.
            if (this.isLoading || !this.hasMore) {
                return;
            }

            this.isLoading = true;

            axios
                .get(route('client.posts.comments.index', this.postId), {
                    // params — это query-строка: ?page=2. У GET нет тела,
                    // поэтому второй аргумент axios.get() — конфиг, а не данные.
                    params: { page: this.nextPage },
                })
                .then((res) => {
                    // Фильтр по уже известным id — защита от дубля на границе страниц
                    // (разбор в п. 11). Set, а не includes(): поиск по множеству
                    // не зависит от длины списка, а список растёт.
                    const known = new Set(this.comments.map((comment) => comment.id));

                    this.comments.push(
                        ...res.data.data.filter((comment) => !known.has(comment.id)),
                    );

                    // Все три значения берём из meta, а не считаем сами: total
                    // приезжает отдельным count(*), и повторить его на клиенте нельзя.
                    this.total = res.data.meta.total;
                    this.lastPage = res.data.meta.last_page;
                    this.nextPage = res.data.meta.current_page + 1;
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
        /**
         * Реакция на событие created от формы.
         *
         * unshift, а не push: список отсортирован «новые сверху», и свежий
         * комментарий должен оказаться первым.
         *
         * total правим на клиенте — это тот редкий случай, когда так можно:
         * мы точно знаем, что записей стало на одну больше, и следующий же
         * запрос за страницей всё равно привезёт настоящее число.
         */
        handleCreated(comment) {
            this.comments.unshift(comment);
            this.total += 1;
        },
    },
};
</script>
```

## 11. Чем бесконечная загрузка отличается от пагинации

Снаружи выглядит как то же самое: сервер отдаёт страницы, клиент их запрашивает. Разница в том, что при обычной пагинации на экране живёт **одна** страница, а при бесконечной загрузке — **все загруженные сразу**. Отсюда два следствия, которых у пагинации нет.

**Первое: границу страниц может сдвинуть новая запись.** `paginate()` — это `LIMIT 10 OFFSET N`, отсчёт от начала выборки. Комментарии отсортированы «новые сверху», а значит новый комментарий встаёт в самое начало и сдвигает всё остальное на одну позицию вниз. Комментарий, который при загрузке страницы 1 был десятым, после этого станет одиннадцатым — то есть первым на странице 2. Пользователь его уже видит, и он приедет второй раз.

В обычной пагинации это незаметно: страницу 2 показывают вместо страницы 1, а не вместе с ней. Здесь — заметно сразу, дублем в списке.

Лечим на клиенте, фильтром по уже известным id (п. 10). Это дешёвая и понятная заплатка. Правильное решение — **курсорная пагинация**:

```php
->cursorPaginate(10);
```

Она отсчитывает не от начала, а от последней увиденной записи: «дай десять с id меньше, чем 137». Новые записи с большими id на такой запрос не влияют вообще, и граница не съезжает. Цена — у `cursorPaginate()` нет `total` и нет номеров страниц: посчитать «сколько всего» она не может по устройству. Для счётчика «Комментарии 42» пришлось бы делать отдельный `count()`, а для бесконечного списка номера страниц и не нужны. Это честный кандидат на следующую итерацию.

**Второе: список растёт и не уменьшается.** Тысяча комментариев — это тысяча компонентов в DOM. Пагинация такой проблемы не создаёт вовсе. Решение (виртуализация: рисовать только видимую часть) далеко за пределами курса, но знать про границу применимости стоит: бесконечная загрузка хороша для десятков и сотен элементов, не для десятков тысяч.

**Почему не средствами Inertia.** У Inertia v2 есть штатный механизм: серверный проп оборачивается в `Inertia::merge()`, и `router.reload({ only: ['comments'], data: { page } })` не заменяет проп, а дописывает в него новые записи; триггером служит компонент `<WhenVisible>`. Кода получилось бы меньше, и это стоит держать в голове как альтернативу. Мы идём через axios по двум причинам. Во-первых, комментарии не участвуют в адресе страницы: `merge` тянет за собой синхронизацию `?page=` с историей браузера, а для вторичного блока под постом это лишнее. Во-вторых, форма добавления в Inertia-варианте тоже должна была бы ходить через Inertia — с редиректом назад и сбросом склеенного списка, — то есть ради одной вставки перезагружалось бы всё. Наш путь длиннее в строках, но каждая строка объяснима.

Отдельно: у Inertia 2.1+ появился ещё более короткий вариант — `Inertia::scroll()` на сервере и компонент `<InfiniteScroll>` на клиенте. В проекте стоит `inertiajs/inertia-laravel` **v2.0.24**, где серверной половины ещё нет, так что это дело будущего обновления, а не текущего урока.

## 12. `Show.vue` после правок

Изменений на странице поста два: кнопка лайка заменена на `LikeButton` (п. 7) и в конце добавлен список комментариев.

IN `resources/js/Pages/Client/Post/Show.vue` — после закрывающего `</article>`:
```vue
    <CommentList :post-id="post.id" />
```

И в скрипте:
```js
import { Head, Link } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';
import LikeButton from '@/Components/LikeButton.vue';
import CommentList from '@/Components/Comment/CommentList.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link, LikeButton, CommentList },
    props: {
        post: {
            type: Object,
            required: true,
        },
    },
};
</script>
```

Страница ужалась до одного пропса и списка компонентов: ни `data()`, ни `methods`, ни `axios`. Весь код уехал в компоненты, и каждый из них можно поставить куда угодно ещё — например, `CommentList` на будущую страницу профиля.

## 13. Тесты

```shell
php artisan make:test --phpunit ClientCommentTest
```

IN `tests/Feature/ClientCommentTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientCommentTest extends TestCase {
    use RefreshDatabase;

    public function test_comments_arrive_by_pages(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        // for($post, 'commentable') — привязка к полиморфному родителю: фабрика
        // сама проставит commentable_id и commentable_type.
        Comment::factory()
            ->count(12)
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        $response = $this->actingAs(Profile::factory()->create()->user)
            ->getJson(route('client.posts.comments.index', $post));

        $response->assertOk()
            // Ровно 10 на странице — это и есть контракт, на который опирается клиент.
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_comments_on_moderation_are_hidden(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        Comment::factory()
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_MODERATE]);

        $response = $this->actingAs(Profile::factory()->create()->user)
            ->getJson(route('client.posts.comments.index', $post));

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_user_adds_comment(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $response = $this->actingAs($profile->user)
            ->postJson(route('client.posts.comments.store', $post), [
                // Отправляем ТОЛЬКО content — ровно то, что есть в форме.
                'content' => 'Первый!',
            ]);

        $response->assertCreated()->assertJsonPath('content', 'Первый!');

        // Проверяем не ответ, а базу: важно, что автором стал профиль из сессии,
        // а статус проставил сервер. Именно это правило легко сломать правкой запроса.
        $this->assertDatabaseHas('comments', [
            'commentable_id' => $post->id,
            'commentable_type' => Post::class,
            'author_id' => $profile->id,
            'content' => 'Первый!',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
    }

    public function test_comment_author_is_taken_from_session(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();
        $stranger = Profile::factory()->create();

        // Пробуем подписаться чужим профилем — поля author_id в форме нет,
        // но подставить его в запрос руками ничто не мешает.
        $this->actingAs($profile->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Не я это написал',
                'author_id' => $stranger->id,
            ])
            ->assertCreated();

        // prepareForValidation() перетирает пришедшее значение своим,
        // поэтому автором остался тот, кто залогинен.
        $this->assertDatabaseHas('comments', [
            'content' => 'Не я это написал',
            'author_id' => $profile->id,
        ]);
    }

    public function test_comment_content_is_required(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.posts.comments.store', $post), ['content' => ''])
            ->assertJsonValidationErrors('content');
    }

    public function test_like_on_comment_toggles(): void {
        $comment = Comment::factory()->create();
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.comments.likes.toggle', $comment))
            ->assertOk()
            ->assertJson(['is_liked' => true, 'likes_count' => 1]);

        // Второй клик по той же кнопке снимает лайк — это и есть toggle.
        $this->actingAs($profile->user)
            ->postJson(route('client.comments.likes.toggle', $comment))
            ->assertOk()
            ->assertJson(['is_liked' => false, 'likes_count' => 0]);
    }
}
```

Самый ценный тест здесь — `test_comment_author_is_taken_from_session`: он проверяет не «работает ли фича», а «нельзя ли её обойти». Такие тесты окупаются лучше всего, потому что руками эту дыру не заметить — форма-то отправляет правильные данные.

## 14. Проверка

1. `php artisan route:list --path=comments` — три маршрута: GET и POST на `posts/{post}/comments`, POST на `comments/{comment}/likes`.
2. `php artisan test --filter=ClientCommentTest` — все тесты зелёные.
3. `/posts/1`: под постом появился блок «Комментарии N», сверху форма, ниже список.
4. Вкладка Network при открытии страницы: `GET /posts/1/comments?page=1` со статусом 200 и телом `{ data, links, meta }`.
5. Прокрутка вниз: не доходя до конца списка уходит `?page=2`, комментарии дописываются снизу, страница не дёргается.
6. Когда страницы кончились — вместо кнопки текст «Это все комментарии», новых запросов нет.
7. Отправка пустой формы: кнопка неактивна. Отправка текста длиннее 2000 символов (через DevTools, минуя `maxlength`) — красное сообщение из 422.
8. Отправка комментария: он появляется первым в списке с вашим ником и текущей датой, счётчик в заголовке растёт на единицу, поле очищается.
9. F5 — комментарий на месте, значит он в базе, а не только на экране.
10. Сердечко на комментарии кликается независимо от сердечка поста и соседних комментариев; после F5 состояние сохраняется.
11. Лайк поста по-прежнему работает и в ленте, и на странице поста — `LikeButton` не сломал старое поведение.
12. Переход в ленту и обратно: в консоли нет ошибок — наблюдатель отключился в `beforeUnmount()`.

## 15. Грабли

- **`Failed to resolve component: CommentList`** — компонент не зарегистрирован в `components` страницы либо путь в `import` не совпал с именем файла. Путь чувствителен к регистру.
- **Белая страница, ошибка Vite в консоли** — новые `.vue`-файлы появились после старта дев-сервера, `composer run dev` нужно перезапустить.
- **404 на `/posts/5/comments`** — забыли импорт `CommentController` в `routes/client.php` либо маршрут объявлен вне группы `auth` и не совпало имя.
- **422 с ошибкой по `author_id`, а не по `content`** — у пользователя нет профиля: `prepareForValidation()` подставил `null`. Сообщение при этом невнятное — повод добавить `attributes()` в запрос.
- **Комментарий сохранился с чужим `author_id`** — `author_id` в `rules()` есть, а в `prepareForValidation()` не подставляется, и значение прошло из тела запроса как есть.
- **500 при отправке: «field commentable_type doesn't have a default value»** — комментарий создан через `Comment::create()` вместо `$post->comments()->create()`. Полиморфные ключи проставляет связь, а не модель.
- **Список пустой, хотя комментарии в базе есть** — у них статус `moderate`. Сидер ставит статус случайно, а `index()` отдаёт только `published`.
- **Сравнение `$comment->status === 1` никогда не истинно** — у `Comment` статусы строковые (`'published'`), в отличие от `Post`.
- **Первая страница приезжает дважды** — нет проверки `if (this.isLoading)` в начале `loadMore()`: наблюдатель сработал одновременно с вызовом из `mounted()`.
- **Загрузка не начинается вовсе** — `lastPage` инициализирован единицей вместо `null`, и `hasMore` ложен с самого начала.
- **Бесконечный цикл запросов до последней страницы** — не задан `rootMargin` наоборот, а `loadMore()` вызывается без проверки `hasMore`; либо триггер остался в области просмотра, потому что список короче экрана.
- **`Cannot read properties of undefined (reading 'observe')`** — `this.$refs.trigger` не найден: элемент-триггер обёрнут в `v-if`, и на момент `mounted()` его в DOM нет.
- **Дубль комментария на границе страниц** — не отфильтровали по известным id после того, как свой комментарий сдвинул выборку (п. 11).
- **Счётчик «Комментарии N» не совпадает со списком** — `total` посчитан на клиенте вместо `meta.total`.
- **Дата выглядит как `Invalid Date`** — не добавлен каст `published_at` в модель, и с сервера приехала строка в формате PostgreSQL.
- **После перехода в ленту в консоли ошибки от наблюдателя** — забыт `beforeUnmount()` с `disconnect()`.
- **Лайк комментария ставится, но счётчик не меняется** — `LikeButton` получил `:initial-count` из ключа, которого нет в ответе. Проверьте `withCount('likedByProfiles')` в `index()`.
- **`storage/logs/comment/retrieved.log` растёт на глазах** — это трейт `HasLog` на модели `Comment`: он пишет строку на каждую прочитанную запись, а бесконечная загрузка читает их пачками. Поведение штатное, но при отладке файл стоит время от времени чистить.

## 16. Что можно сделать лучше

**`PostPolicy` — правило «кому виден пост» записано уже трижды.** `PostController::show()`, `PostController::destroy()` и теперь `CommentController::abortUnlessPostVisible()`. Политика собрала бы это в два метода (`view` и `delete`), а в контроллерах остался бы `Gate::authorize()`. Дальше — `CommentPolicy` с правилом «комментарий мой» и кнопка удаления комментария.

**`cursorPaginate()` вместо `paginate()`.** Убирает съезд границы по-настоящему, а не заплаткой на клиенте (п. 11). Потребует отдельного `count()` для заголовка и перехода с `page` на `cursor` в клиентском коде.

**Общий сервис лайков.** `PostController::toggleLike()` и `CommentController::toggleLike()` отличаются одной строкой. Метод `LikeService::toggle(Model $likeable, Profile $profile): array` убрал бы дублирование сразу и пригодился бы изображениям, которые тоже лайкаемые по схеме.

**Проверка «а этот комментарий вообще видно» при лайке.** Сейчас `toggleLike()` не смотрит на родительский пост: лайк на комментарии к чужому неопубликованному посту пройдёт. Дыра небольшая (id надо ещё угадать), но честнее её закрыть — и естественнее всего это делается политикой.

**`attributes()` и `messages()` в `StoreRequest`.** Сейчас при пустом профиле пользователь увидит «Поле author id обязательно» — сообщение про поле, которого он не заполнял. Русские названия полей и свои тексты ошибок это чинят.

**Ответы на комментарии.** Схема их поддерживает: `commentable` у комментария может указывать на другой комментарий. Понадобится рекурсивный компонент (`ItemComment`, который рисует внутри себя `ItemComment`), форма ответа и своя подгрузка веток.

**Первая страница комментариев пропсом страницы.** Сейчас список делает лишний round-trip: страница пришла, и только потом уходит запрос за комментариями. Отдать первую страницу прямо из `PostController::show()` — минус один запрос и минус «мигание» пустого блока. Цена — выборка комментариев окажется в двух местах.

**Оптимистичная вставка комментария.** Показывать комментарий сразу, до ответа сервера, и убирать при ошибке. Список станет отзывчивее, но придётся держать «временный» id и уметь его подменять.

**Модерация комментариев.** Константа `STATUS_MODERATE` в модели есть, а пользоваться ей некому. Появится админский экран — и `prepareForValidation()` начнёт ставить статус по настройке, а автору нужно будет показывать свои комментарии «на модерации» так же, как посты в «Моих публикациях».
