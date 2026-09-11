# Lesson 26 - Ответы на комментарии: ветка в один уровень

Цель урока: под каждым комментарием появляется кнопка «Ответить» с формой, ответы сохраняются в ту же таблицу `comments`, а рядом встаёт «Показать ответы (n)», которая разворачивает ветку. Дерево показываем ровно на один уровень: у ответа своих ответов нет.

По пути разбираются: **полиморфная связь, направленная на саму себя** (`Comment::comments()`) и почему список комментариев поста при этом не «протёк» ответами; `withCount()` с **псевдонимом и условием** (`comments as replies_count`) и парный ему `whenCounted()`; переиспользование одного `FormRequest` для двух разных родителей; обобщение компонента через **готовый URL** — тот же приём, что у `LikeButton` в 25-м уроке, теперь применённый к форме; и главный сюжет клиентской части — **рекурсивный Vue-компонент** и проп, который ограничивает его глубину.

Отправная точка — состояние после 25-го урока: под постом работает список комментариев с бесконечной загрузкой, формой и лайками.

## 1. Карта урока

Новых файлов в уроке нет. Весь урок — правки существующего: фича ложится на уже готовую схему и уже написанные компоненты. Это нормальный и частый режим работы.

Что правится:

| Файл | Что меняется |
| --- | --- |
| `routes/client.php` | два маршрута: список ответов и создание ответа |
| `app/Http/Controllers/Client/CommentController.php` | методы `replies()` и `storeReply()`, счётчик ответов в `index()` |
| `app/Http/Resources/Comment/CommentResource.php` | ключ `replies_count` |
| `resources/js/Components/Comment/CommentForm.vue` | вместо `postId` — готовый `url` и свой `placeholder` |
| `resources/js/Components/Comment/CommentList.vue` | передаёт форме `url` вместо `postId` |
| `resources/js/Components/Comment/ItemComment.vue` | кнопка «Ответить», форма ответа, «Показать ответы (n)», рекурсивный вывод ветки |
| `tests/Feature/ClientCommentTest.php` | четыре теста на ответы |

Команд `make:` в уроке нет: контроллер, запрос, ресурс и все компоненты уже созданы. В конце понадобятся только `vendor/bin/pint --dirty` и прогон тестов.

## 2. Что уже готово в схеме

Схема поддерживает ветки с миграции 28.05, трогать её не придётся — стоит только свериться, что именно есть.

**Таблица `comments`** хранит `morphs('commentable')`: пару `commentable_id` + `commentable_type`. Комментарий к посту — строка с `commentable_type = App\Models\Post`, ответ на комментарий — строка с `commentable_type = App\Models\Comment`. Отдельной таблицы для ответов нет и не нужно: ответ — такой же комментарий, просто с другим родителем.

**Модель `Comment`** уже объявляет обе стороны связи:

```php
// «Мой родитель»: пост или другой комментарий.
public function commentable(): MorphTo { return $this->morphTo(); }

// «Мои дети»: комментарии, у которых родитель — я. Это и есть ответы.
public function comments(): MorphMany { return $this->morphMany(Comment::class, 'commentable'); }
```

`comments()` на `Comment` — связь модели с самой собой. Ничего особенного в ней нет: `morphMany` ищет строки, где `commentable_id = $this->id` и `commentable_type = Comment::class`. Имя связи оставляем как есть (`comments`), а «ответами» называем их на границе с клиентом — там это роль, а не сущность.

**Важное следствие, которое уже работает в нашу пользу.** `CommentController::index()` строит выборку от `$post->comments()`, то есть отбирает строки с `commentable_type = Post::class`. Ответы туда не попадают **сами по себе**, без единой дополнительной проверки — у них другой тип родителя. Ради этого полиморфная связь и заводилась: «комментарии поста» и «ответы на комментарий» — один и тот же запрос с разными параметрами.

Чего нет: маршрутов для ветки, счётчика ответов в ответе сервера и какой-либо разметки для ответов.

## 3. Где живёт правило «один уровень»

Задание требует показывать дерево в один уровень. Это требование можно выполнить в трёх разных местах, и выбрать нужно осознанно:

1. **Только на клиенте**: сервер разрешает любую вложенность, а компонент рисует лишь первый уровень. Плохо: в базе накопятся ответы третьего уровня, которых никто никогда не увидит.
2. **Только на сервере**: ответ на ответ запрещён, у клиента ветка физически не может стать глубже. Хорошо, но кнопка «Ответить» под ответом всё равно видна и приведёт к ошибке.
3. **В обоих местах**: сервер отказывается создавать ответ на ответ, а клиент не показывает кнопку там, где ответить нельзя.

Берём третий вариант, и это общее правило, а не частность урока: **клиент прячет то, что сервер запрещает**. Клиентская проверка — про удобство (не показывать кнопку, которая всё равно не сработает), серверная — про само правило (его нельзя обойти). Если оставить только клиентскую, правило обходится одной строкой в консоли браузера.

На сервере это одна проверка в контроллере (п. 6), на клиенте — один проп `canReply` (п. 8).

## 4. Маршруты

IN `routes/client.php` — в ту же группу `auth`, следом за маршрутами комментариев:
```php
    // Ответы на комментарий. Адрес вложен в комментарий, а не в пост: ветка
    // принадлежит конкретному комментарию, и id поста для неё избыточен.
    //
    // Последний сегмент — replies, хотя модель та же самая Comment. В URL мы
    // называем РОЛЬ, а не класс: comments/5/comments читалось бы как опечатка,
    // а comments/5/replies сразу говорит, что лежит по адресу.
    Route::get('comments/{comment}/replies', [CommentController::class, 'replies'])
        ->whereNumber('comment')
        ->name('client.comments.replies.index');

    // Тот же адрес, POST — добавить ответ в ветку. Полная симметрия с парой
    // client.posts.comments.index / .store: GET читает список, POST дописывает.
    Route::post('comments/{comment}/replies', [CommentController::class, 'storeReply'])
        ->whereNumber('comment')
        ->name('client.comments.replies.store');
```

Импорт `CommentController` в файле уже есть — его используют маршруты комментариев и лайка.

Почему не завели отдельный `ReplyController`: контроллер отвечает за ресурс, а ресурс здесь один — комментарий. Ответ отличается от комментария только родителем, и все четыре метода работают с одной моделью, одним ресурсом и одним `FormRequest`. Отдельный контроллер дал бы второе место, куда нужно не забыть внести правку.

## 5. `replies_count`: счётчик ответов в ответе сервера

Кнопке «Показать ответы (n)» число нужно ДО того, как ветку загрузили, — иначе кнопку не на чем нарисовать. Значит, число должно приехать вместе с самим комментарием.

IN `app/Http/Controllers/Client/CommentController.php`, метод `index()` — строка `->withCount('likedByProfiles')` заменяется на:
```php
            ->withCount([
                'likedByProfiles',
                // Псевдоним + условие. Связь называется comments, а клиенту нужен
                // ключ replies_count — «as replies_count» переименовывает результат.
                //
                // Условие обязательно: считать надо ровно то, что потом покажем.
                // Без него кнопка обещала бы «Показать ответы (3)», а разворачивала
                // один: ответы на модерации в список не попадут.
                'comments as replies_count' => fn (Builder $query) => $query
                    ->where('status', Comment::STATUS_PUBLISHED),
            ])
```

`withCount()` не делает второго запроса: Eloquent добавляет к основному SELECT подзапрос `(select count(*) ...) as replies_count`. Для списка из десяти комментариев это по-прежнему один поход в базу.

IN `app/Http/Resources/Comment/CommentResource.php` — рядом с `likes_count`:
```php
            // whenCounted('replies') ищет атрибут replies_count — то есть имя
            // берётся из ПСЕВДОНИМА запроса, а не из имени связи. Ключ условный:
            // там, где withCount() не звали (например, в ответе на создание
            // комментария), его в JSON просто не будет, и клиент подставит 0.
            'replies_count' => $this->whenCounted('replies'),
```

## 6. Контроллер: чтение и запись ветки

IN `app/Http/Controllers/Client/CommentController.php` — два новых публичных метода и один приватный помощник.

```php
    /**
     * Ответы на комментарий.
     *
     * Возвращает ВСЮ ветку целиком, без пагинации, — и это осознанный выбор,
     * а не забывчивость. Ветку открывают по кнопке, её размер известен заранее
     * (replies_count уже приехал), и в один уровень она короткая. Бесконечная
     * загрузка внутри бесконечной загрузки усложнила бы компонент вдвое ради
     * случая, которого в проекте пока нет.
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
     * Пост, которому принадлежит комментарий, — и одновременно проверка «это
     * комментарий к посту, а не ответ».
     *
     * Оба вызова (чтение ветки и запись в неё) начинаются с него, и оба правила
     * урока выполняются здесь разом:
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
```

Добавляется импорт `use Illuminate\Http\Resources\Json\AnonymousResourceCollection;`.

Обратите внимание, сколько кода НЕ пришлось писать: ни новой валидации, ни новой модели, ни миграции, ни отдельного ресурса. Ответ — это комментарий, и всё, что уже написано для комментария, работает для него бесплатно. Так выглядит выгода от полиморфной связи, выбранной при проектировании схемы.

## 7. `CommentForm` становится универсальной

Сейчас форма знает лишнее: она сама собирает адрес из `postId`.

```js
axios.post(route('client.posts.comments.store', this.postId), ...)
```

Для ответа адрес другой, и напрашивается второй проп с флагом «а это ответ». Так делать не нужно — это ровно та развилка, которую мы проходили с `LikeButton` в 25-м уроке: **компонент не должен знать, кому он пишет, ему достаточно знать, куда слать**. Отдаём наружу готовый URL, и форма одинаково работает и для комментария, и для ответа.

IN `resources/js/Components/Comment/CommentForm.vue` — блок `props` целиком заменяется на:
```js
    props: {
        // Готовый адрес вместо postId. Собрать его — забота того, кто ставит
        // форму: список знает пост, элемент комментария знает комментарий.
        url: {
            type: String,
            required: true,
        },
        // Единственное, что ещё отличает форму ответа, — подпись в поле.
        // Проп со значением по умолчанию: у списка ничего не меняется.
        placeholder: {
            type: String,
            default: 'Написать комментарий…',
        },
    },
```

В разметке `placeholder="Написать комментарий…"` становится `:placeholder="placeholder"` (двоеточие обязательно: теперь это выражение, а не строка).

В методе `submit()` запрос начинается так:
```js
            axios
                .post(this.url, { content: this.content })
```

IN `resources/js/Components/Comment/CommentList.vue` — вызов формы:
```vue
        <CommentForm
            :url="route('client.posts.comments.store', postId)"
            @created="handleCreated"
        />
```

Проп `postId` у самого `CommentList` остаётся: список по-прежнему грузит страницы по этому id.

## 8. `ItemComment`: кнопка, форма и ветка

Главный файл урока. Комментарий перестаёт быть чисто отрисовочным компонентом: у него появляется собственное состояние — открыта ли форма ответа и развёрнута ли ветка.

IN `resources/js/Components/Comment/ItemComment.vue`:
```vue
<template>
    <article class="border-t border-gray-100 py-3">
        <p class="text-xs text-gray-400">
            {{ comment.author?.nickname ?? 'Аноним' }} · {{ publishedAt }}
        </p>

        <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ comment.content }}</p>

        <!-- Панель действий: лайк, «Ответить», «Показать ответы». -->
        <div class="mt-2 flex items-center gap-4 text-xs">
            <LikeButton
                :url="route('client.comments.likes.toggle', comment.id)"
                :initial-liked="comment.is_liked"
                :initial-count="comment.likes_count"
                icon-class="h-4 w-4"
            />

            <!--
                canReply закрывает сразу обе кнопки. У ответа ветки быть не может
                (сервер её не создаст), и предлагать ответить на ответ тоже нельзя —
                запрос вернёт 404. Один проп держит оба ограничения.
            -->
            <button
                v-if="canReply"
                type="button"
                class="text-gray-400 hover:text-sky-700"
                @click="isReplyFormShown = !isReplyFormShown"
            >
                {{ isReplyFormShown ? 'Отмена' : 'Ответить' }}
            </button>

            <!--
                Кнопки ветки нет, пока ответов нет: «Показать ответы (0)» бессмысленно.
                Как только придёт первый ответ, repliesCount станет единицей —
                и кнопка появится сама, реактивно.
            -->
            <button
                v-if="canReply && repliesCount > 0"
                type="button"
                class="text-gray-400 hover:text-sky-700"
                @click="toggleReplies"
            >
                {{ areRepliesShown ? 'Скрыть ответы' : `Показать ответы (${repliesCount})` }}
            </button>
        </div>

        <!--
            Форма ответа — та же CommentForm, что и над списком. Отличий два:
            другой адрес и другая подпись поля.
        -->
        <CommentForm
            v-if="isReplyFormShown"
            :url="route('client.comments.replies.store', comment.id)"
            placeholder="Ваш ответ…"
            class="mt-3"
            @created="handleReplyCreated"
        />

        <div v-if="areRepliesShown" class="mt-2 border-l-2 border-gray-100 pl-4">
            <p v-if="isLoadingReplies" class="py-2 text-xs text-gray-400">Загружаю ответы…</p>

            <!--
                Компонент вызывает сам себя. Это законный приём: ответ — такой же
                комментарий, и рисовать его нужно так же. Ограничитель — :can-reply="false":
                внутри ответа обе кнопки исчезнут, и рекурсия закончится на первом уровне.
            -->
            <ItemComment
                v-for="reply in replies"
                :key="reply.id"
                :comment="reply"
                :can-reply="false"
            />
        </div>
    </article>
</template>

<script>
import axios from 'axios';
import CommentForm from '@/Components/Comment/CommentForm.vue';
import LikeButton from '@/Components/LikeButton.vue';

export default {
    // Для рекурсии name обязателен: себя компонент находит по имени, импортировать
    // сам себя он не может. Строка ниже — не документация, а рабочий код.
    name: 'ItemComment',
    components: { CommentForm, LikeButton },
    props: {
        comment: {
            type: Object,
            required: true,
        },
        // Единственная разница между комментарием и ответом на клиенте.
        // Значение по умолчанию true — список ничего передавать не обязан.
        canReply: {
            type: Boolean,
            default: true,
        },
    },
    data() {
        return {
            isReplyFormShown: false,
            areRepliesShown: false,
            // «Ветку уже привозили» — чтобы второе открытие не било в сервер.
            areRepliesLoaded: false,
            isLoadingReplies: false,
            replies: [],
            // Локальная копия счётчика: он будет меняться при отправке ответа,
            // а пропсы менять нельзя — они принадлежат родителю.
            //
            // ?? 0 — не украшение: ключа replies_count нет в ответе на создание
            // комментария (withCount() там не звали), и у только что отправленного
            // комментария приедет undefined.
            repliesCount: this.comment.replies_count ?? 0,
        };
    },
    computed: {
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
    methods: {
        /**
         * Ветка грузится лениво — при первом развороте, а не вместе со списком.
         *
         * Это главная причина, по которой ответы вообще сделаны отдельным запросом:
         * десять комментариев со всеми ветками — это десятки лишних записей в JSON,
         * которые в большинстве случаев никто не откроет.
         */
        toggleReplies() {
            this.areRepliesShown = !this.areRepliesShown;

            if (this.areRepliesShown && !this.areRepliesLoaded) {
                this.loadReplies();
            }
        },
        loadReplies() {
            if (this.isLoadingReplies) {
                return;
            }

            this.isLoadingReplies = true;

            axios
                .get(route('client.comments.replies.index', this.comment.id))
                .then((res) => {
                    // Пагинации нет, поэтому берём res.data.data целиком —
                    // обёртка data осталась от коллекции ресурса.
                    this.replies = res.data.data;
                    // Синхронизируем счётчик с тем, что реально приехало: пока
                    // страница висела открытой, ответов могло стать больше.
                    this.repliesCount = this.replies.length;
                    this.areRepliesLoaded = true;
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isLoadingReplies = false;
                });
        },
        /**
         * Событие created от формы ответа.
         *
         * Две ситуации, и их важно различать. Если ветка уже загружена — просто
         * дописываем ответ в конец (порядок хронологический, поэтому push,
         * а не unshift, как в списке). Если нет — грузим её целиком: свежий
         * ответ приедет вместе с остальными, и дубля не будет.
         */
        handleReplyCreated(reply) {
            this.isReplyFormShown = false;
            this.areRepliesShown = true;

            if (this.areRepliesLoaded) {
                this.replies.push(reply);
                this.repliesCount += 1;

                return;
            }

            this.loadReplies();
        },
    },
};
</script>
```

**Про рекурсию.** Компонент, который рисует сам себя, — штатный приём для деревьев, и Vue поддерживает его без хитростей: достаточно `name`. Опасность у него ровно одна — **отсутствие условия остановки**. Компонент без ограничителя уходит вглубь, пока браузер не упрётся в предел стека. У нас ограничитель — проп `canReply`: на втором уровне обе кнопки скрыты, ветка не грузится, и рекурсия дальше не идёт. В данных то же самое гарантирует `postOfComment()` на сервере.

Правило для таких компонентов: **сначала пишется условие остановки, потом сам вызов**. Не наоборот.

## 9. Тесты

Дописываем в существующий `tests/Feature/ClientCommentTest.php` — новых файлов не заводим, речь о том же ресурсе.

```php
    public function test_replies_arrive_without_moderated(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $comment = Comment::factory()
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        // Тот же for(..., 'commentable'), только родитель теперь комментарий.
        // Ровно этим ответ и отличается от комментария в базе.
        Comment::factory()
            ->count(3)
            ->for($comment, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        Comment::factory()
            ->for($comment, 'commentable')
            ->create(['status' => Comment::STATUS_MODERATE]);

        $this->actingAs(Profile::factory()->create()->user)
            ->getJson(route('client.comments.replies.index', $comment))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_replies_do_not_leak_into_post_comments(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $comment = Comment::factory()
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        Comment::factory()
            ->count(2)
            ->for($comment, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        $this->actingAs(Profile::factory()->create()->user)
            ->getJson(route('client.posts.comments.index', $post))
            ->assertOk()
            // Самый ценный тест урока: список поста показывает ОДНУ запись,
            // хотя в таблице их три. Ответы в него не попали — это работа
            // полиморфной связи, и сломать её легко неаккуратным where().
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.replies_count', 2);
    }

    public function test_user_replies_to_comment(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $comment = Comment::factory()
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.comments.replies.store', $comment), [
                'content' => 'Согласен',
            ])
            ->assertCreated()
            ->assertJsonPath('content', 'Согласен');

        // Проверяем родителя: и id, и тип. Без commentable_type ответ был бы
        // неотличим от комментария к посту с тем же номером.
        $this->assertDatabaseHas('comments', [
            'commentable_id' => $comment->id,
            'commentable_type' => Comment::class,
            'author_id' => $profile->id,
            'content' => 'Согласен',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
    }

    public function test_reply_to_reply_is_not_allowed(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $comment = Comment::factory()
            ->for($post, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);
        $reply = Comment::factory()
            ->for($comment, 'commentable')
            ->create(['status' => Comment::STATUS_PUBLISHED]);

        // Кнопки в интерфейсе нет, но запрос отправить ничто не мешает —
        // и вот это правило проверяет, что «один уровень» живёт на сервере,
        // а не только в разметке.
        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.comments.replies.store', $reply), [
                'content' => 'Третий уровень',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('comments', ['content' => 'Третий уровень']);
    }
```

Два теста здесь проверяют не фичу, а её границы: `test_replies_do_not_leak_into_post_comments` ловит ошибку, из-за которой ответы задвоятся в интерфейсе, а `test_reply_to_reply_is_not_allowed` — обход ограничения в обход интерфейса. Такие ошибки руками не воспроизводятся.

## 10. Проверка

1. `php artisan route:list --path=replies` — два маршрута: GET и POST на `comments/{comment}/replies`.
2. `php artisan test --filter=ClientCommentTest` — все тесты, старые и новые, зелёные.
3. `vendor/bin/pint --dirty` — правок стиля нет.
4. `/posts/1`: под каждым комментарием появилась строка действий — сердечко, «Ответить», и у тех, где ответы уже есть (их насыпал сидер), «Показать ответы (n)».
5. Клик по «Ответить»: раскрывается форма с подписью «Ваш ответ…», кнопка меняется на «Отмена». Повторный клик закрывает форму.
6. Форма открывается только у того комментария, по которому кликнули: состояние живёт в элементе, а не в списке.
7. Отправка ответа: форма закрывается, ветка раскрывается, ответ виден последним, счётчик в кнопке вырос на единицу.
8. F5 — ответ на месте, в списке комментариев поста он отдельной записью не появился.
9. Клик по «Показать ответы (n)»: во вкладке Network уходит `GET /comments/5/replies` со статусом 200 и телом `{ data: [...] }`. Повторное сворачивание и разворачивание нового запроса НЕ делает.
10. Внутри ответа нет ни «Ответить», ни «Показать ответы» — только сердечко.
11. Сердечко на ответе работает и после F5 сохраняет состояние.
12. Счётчик «Комментарии N» в заголовке после отправки ответа не изменился: ответ — не комментарий поста.
13. `POST /comments/{id-ответа}/replies` из консоли браузера возвращает 404.

## 11. Грабли

- **Вкладка виснет, в консоли `Maximum recursive updates exceeded`** — забыт `:can-reply="false"` у вложенного `ItemComment`: компонент разворачивает ветку внутри ветки бесконечно.
- **`Failed to resolve component: ItemComment`** — у компонента не задан `name: 'ItemComment'`. Себя по имени файла он не найдёт.
- **Список комментариев перестал отправляться, в консоли `Missing required prop: url`** — в `CommentList.vue` остался `:post-id` вместо `:url` после правки формы.
- **Форма ответа отправляет комментарий к посту** — в `CommentForm` остался старый `route('client.posts.comments.store', ...)` вместо `this.url`.
- **В поле буквально написано `placeholder`** — в разметке забыто двоеточие: `placeholder="placeholder"` вместо `:placeholder="placeholder"`.
- **Кнопки «Показать ответы» нет, хотя ответы в базе есть** — не добавлен `withCount(['comments as replies_count' => ...])` в `index()` либо ключ в `CommentResource`. Проверяется во вкладке Network: в JSON комментария не будет `replies_count`.
- **Счётчик показывает больше, чем разворачивается** — в `withCount()` забыто условие по статусу: посчитались ответы на модерации.
- **404 при отправке ответа** — отвечаем на ответ (это ожидаемо, п. 6) либо у комментария `commentable` не пост: такие строки бывают после ручных правок в базе.
- **500 «commentable_type doesn't have a default value»** — ответ создан через `Comment::create()` вместо `$comment->comments()->create()`. Полиморфные ключи проставляет связь.
- **Ответы дублируются после отправки** — в `handleReplyCreated()` выполняются и `push()`, и `loadReplies()`: не проверен флаг `areRepliesLoaded`.
- **Ветка грузится заново при каждом клике** — не выставляется `areRepliesLoaded = true` после успешной загрузки.
- **Ответы приехали без сердечка или со счётчиком 0** — в `replies()` забыты `withCount('likedByProfiles')` и `withExists(...)`.
- **Ответ появился в общем списке комментариев отдельной записью** — выборка в `index()` построена не от `$post->comments()`: тип родителя перестал фильтровать.
- **Форма ответа открылась сразу у всех комментариев** — флаг `isReplyFormShown` заведён в `CommentList`, а не в `ItemComment`: одно состояние на весь список.
- **Ветка пустая после отправки первого ответа** — `areRepliesShown` не выставлен в `handleReplyCreated()`: ответ ушёл в базу, но блок остался свёрнутым.

## 12. Что можно сделать лучше

**Первые ответы прямо в списке.** Соцсети обычно показывают один-два ответа сразу, а «показать все» предлагают только для длинных веток. Делается через `->with(['comments' => fn ($q) => $q->latest('id')->limit(2)])` и заметно сокращает число кликов. Цена — тяжелее JSON списка.

**Пагинация ветки.** Сейчас `replies()` отдаёт всю ветку разом. Под популярным комментарием это сотни записей. `cursorPaginate()` и кнопка «Ещё ответы» решают вопрос тем же приёмом, что уже освоен в `CommentList`.

**Дерево глубже одного уровня.** Схема это позволяет: `commentable` у ответа может указывать на ответ. Понадобится ограничение глубины (иначе ветка уходит вправо до края экрана), рекурсивная загрузка и, скорее всего, «свернуть ветку целиком». Один уровень — сознательный компромисс, а не предел схемы.

**Политики.** «Кому виден пост» теперь записано в четырёх местах — `postOfComment()` добавил пятое обращение к тому же правилу. `PostPolicy` и `CommentPolicy` собрали бы это в одно место и дали бы естественный дом проверке «этот комментарий мой» для будущей кнопки удаления.

**Удаление своего комментария и ответа.** Кнопка уже есть у поста (`DeletePost.vue` из 24-го урока), и здесь она напрашивается. Отдельный вопрос — что делать с веткой при удалении родителя: удалять каскадом или оставлять «комментарий удалён».

**Уведомление автору комментария.** «Вам ответили» — классический повод познакомиться с событиями и очередями: событие в `storeReply()` и слушатель, который шлёт уведомление в фоне.

**Автофокус в форме ответа.** Сейчас после клика по «Ответить» нужно ещё раз кликнуть в поле. Лечится `ref` на `textarea` и `focus()` в `mounted()` формы — три строки, заметное удобство.

**Ответ с упоминанием.** Кнопка «Ответить» под ответом могла бы не исчезать, а подставлять `@ник` в форму ответа РОДИТЕЛЬСКОГО комментария. Так устроены плоские ветки в большинстве соцсетей: визуально один уровень, а адресат виден из текста.
