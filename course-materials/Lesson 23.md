# Lesson 23 - Клиентская часть: лента, страница поста и лайки

Цель урока: выйти из админки и собрать то, что видит обычный пользователь, — ленту публикаций, страницу отдельного поста с лайком и страницу собственных постов. Серверная логика почти вся уже написана в уроках 13-21: те же модели, тот же `PostResource`, та же пагинация. Новое здесь — вторая «половина» приложения со своим файлом маршрутов, своей раскладкой и своим набором контроллеров, а из Eloquent — `toggle()` на связи многие-ко-многим и признак «я это лайкнул» через `withExists()`.

По пути разбираются: отдельный файл маршрутов и `then:` в `bootstrap/app.php`; вторая раскладка (`ClientLayout`) и свойство `layout` у страницы; выборка ленты с `withCount` и `withExists`; `whenHas()` в ресурсе; пагинация Inertia-ссылками вместо axios; `toggle()` и что он возвращает; почему ленту нельзя закэшировать так же, как админский список.

Отправная точка — состояние после 21-го урока: админка постов умеет всё (список с фильтром и пагинацией, создание, редактирование, удаление, кэш списка), клиентской части нет вообще. 22-й урок был обзорным, нового материала в нём нет.

Подписок в схеме БД пока нет, поэтому «лента подписок» на этом этапе показывает все опубликованные посты. Таблица подписок и фильтрация ленты по ней появятся отдельным уроком; всё, что написано ниже, к этому моменту менять не придётся — изменится одна строка выборки в `FeedController::index()`.

## 1. Карта урока

Что появляется:

| Файл | Роль |
| --- | --- |
| `routes/client.php` | маршруты клиентской части, отдельно от админских |
| `app/Http/Controllers/Client/FeedController.php` | лента |
| `app/Http/Controllers/Client/PostController.php` | страница поста, переключение лайка |
| `app/Http/Controllers/Client/ProfileController.php` | лента собственных постов |
| `resources/js/Layouts/ClientLayout.vue` | вторая раскладка приложения |
| `resources/js/Pages/Client/Feed/Index.vue` | лента |
| `resources/js/Pages/Client/Post/Show.vue` | пост целиком + кнопка лайка |
| `resources/js/Pages/Client/Profile/Personal.vue` | «Мои публикации» |

Что правится: `bootstrap/app.php` (подключение файла маршрутов) и `PostResource` (поля `author` и `is_liked`).

Команды для генерации PHP-классов (Vue-файлы и `routes/client.php` создаются руками — генератора для них нет):

```shell
php artisan make:controller Client/FeedController --no-interaction
php artisan make:controller Client/PostController --no-interaction
php artisan make:controller Client/ProfileController --no-interaction
```

Обратите внимание на неймспейс `Client\`: `Admin\PostController`, `Api\PostController` и `Client\PostController` — три разных класса с одним именем. Разделение по папкам здесь не косметика, а способ держать три разных набора правил (что показываем, кому и в каком формате) в трёх независимых местах: админка отдаёт всё и всем сотрудникам, API — JSON по токену, клиент — только опубликованное и с оглядкой на текущий профиль.

Контроллер называется по ресурсу, а не по экрану: `client.posts.show` и `client.posts.likes.toggle` — это действия над постом, и живут они в `PostController`. `FeedController` остаётся с единственным методом `index()`, и это нормально: лента — самостоятельный ресурс, у неё просто нет других действий.

## 2. Отдельный файл маршрутов `routes/client.php`

Клиентские маршруты можно было дописать в `web.php` — там уже лежат админские. Но `web.php` и так вырос до полусотни строк, а клиентская часть — это отдельный раздел приложения со своим префиксом имён (`client.*`). Laravel штатно поддерживает дополнительные файлы маршрутов.

IN `bootstrap/app.php`:
```php
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // then — «после того, как фреймворк зарегистрировал свои файлы, зарегистрируй ещё вот эти».
        // Внутри замыкания мы сами решаем, какая middleware-группа, префикс URL и префикс имён
        // достанутся файлу.
        then: function (): void {
            // middleware('web') обязателен: именно эта группа даёт сессию, куку XSRF-TOKEN
            // и middleware HandleInertiaRequests. Без неё маршруты формально работают,
            // но auth() не видит пользователя, а Inertia не отдаёт страницу.
            Route::middleware('web')->group(base_path('routes/client.php'));
        },
    )
```

Альтернатива — `require __DIR__.'/client.php';` в конце `web.php`, как Breeze подключает `auth.php`. Она короче, но файл тогда не самостоятельный: он наследует всё окружение `web.php` и его нельзя отдать другой middleware-группе или префиксу. Для раздела приложения выбираем `then:` — это и есть штатный способ «завести новый файл маршрутов».

Префикс URL здесь сознательно не задаём: адреса клиентской части — это корневые `/feed`, `/posts/5`, а не `/client/feed`. Пользователь не должен видеть в адресной строке наши внутренние деления.

## 3. Маршруты урока

IN `routes/client.php`:
```php
<?php

use App\Http\Controllers\Client\FeedController;
use App\Http\Controllers\Client\PostController;
use App\Http\Controllers\Client\ProfileController;
use Illuminate\Support\Facades\Route;

// Весь файл под auth: гостевого режима у ленты пока нет. Лайк ставит профиль текущего
// пользователя, «мои публикации» без пользователя не существуют, и даже лента опирается
// на профиль — ей нужно знать, какие посты уже лайкнуты.
Route::middleware('auth')->group(function () {
    Route::get('feed', [FeedController::class, 'index'])->name('client.feed.index');

    // Страница «мои публикации». Слово personal — не id профиля, а фиксированный сегмент:
    // чей профиль показывать, сервер знает из сессии, а не из URL. Чужие профили
    // приедут отдельным маршрутом profiles/{profile} позже.
    Route::get('profiles/personal', [ProfileController::class, 'personal'])
        ->name('client.profiles.personal');

    // whereNumber — та же защита, что в админке: сегмент ограничен регуляркой [0-9]+,
    // и маршрут перестаёт зависеть от порядка объявления.
    Route::get('posts/{post}', [PostController::class, 'show'])
        ->whereNumber('post')
        ->name('client.posts.show');

    // Переключение лайка. POST, а не GET: действие меняет состояние на сервере,
    // а GET обязан быть безопасным (его повторяет браузер, префетчит, кладёт в историю).
    //
    // URL читается как «лайки этого поста», глагол toggle живёт в имени маршрута,
    // а не в адресе: posts/5/likes/toggle было бы RPC-стилем, а не REST.
    //
    // Почему не пара store/destroy, как у ресурса: клиент не знает наверняка, стоит ли
    // сейчас лайк (соседняя вкладка могла его снять), и выбирать между POST и DELETE ему
    // пришлось бы по устаревшим данным. Один идемпотентный по смыслу маршрут «переключи»
    // снимает вопрос — решение принимает сервер.
    Route::post('posts/{post}/likes', [PostController::class, 'toggleLike'])
        ->whereNumber('post')
        ->name('client.posts.likes.toggle');
});
```

Имена маршрутов с префиксом `client.` — то же, что `admin.` в `web.php`: во Vue мы зовём `route('client.posts.show', post.id)`, и по имени сразу видно, к какой половине приложения относится страница.

Контроллеры распределены по ресурсам, а не по страницам: `feed` — в `FeedController`, оба `posts/*` — в `PostController`, `profiles/*` — в `ProfileController`. Имя маршрута при таком делении читается как адрес кода: `client.posts.likes.toggle` — это «клиентская часть → ресурс posts → лайки → действие toggle», и найти метод получается не заглядывая в `route:list`.

## 4. `ClientLayout.vue` — вторая раскладка

Раскладка — обычный Vue-компонент со `<slot />`, куда Inertia вставляет страницу. Отличие от админской в том, что клиентская шапка горизонтальная и в ней есть имя пользователя.

IN `resources/js/Layouts/ClientLayout.vue`:
```vue
<template>
    <div class="min-h-screen bg-gray-100">
        <header class="bg-white shadow">
            <nav class="mx-auto flex max-w-3xl items-center gap-6 px-4 py-4">
                <Link
                    :href="route('client.feed.index')"
                    class="text-sm font-semibold text-gray-900 hover:text-sky-700"
                >
                    Лента
                </Link>

                <Link
                    :href="route('client.profiles.personal')"
                    class="text-sm font-semibold text-gray-900 hover:text-sky-700"
                >
                    Мои публикации
                </Link>

                <!--
                    $page.props.auth.user — общий проп, который кладёт в каждый ответ
                    HandleInertiaRequests::share(). Его не нужно передавать из контроллера:
                    он приезжает на любую Inertia-страницу приложения.
                -->
                <span class="ml-auto text-sm text-gray-500">
                    {{ $page.props.auth.user.name }}
                </span>
            </nav>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-6">
            <slot />
        </main>
    </div>
</template>

<script>
import { Link } from '@inertiajs/vue3';

export default {
    name: 'ClientLayout',
    components: { Link },
};
</script>
```

`max-w-3xl` вместо табличной ширины админки — лента читается в одну колонку, как в любой соцсети; широкая колонка текста читается плохо.

Страница подключает раскладку свойством `layout` (как `AdminLayout` в админских страницах). Напоминание из 15-го урока: `layout` — свойство Inertia, а не Vue. При переходе между страницами с одной и той же раскладкой компонент раскладки не пересоздаётся — меняется только содержимое `<slot />`, поэтому шапка не мигает.

## 5. `FeedController::index()` — выборка ленты

IN `app/Http/Controllers/Client/FeedController.php`:
```php
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
     * В отличие от админского index(), здесь одна ветка ответа: страница всегда приезжает
     * через Inertia. Фильтра нет, запросов от axios нет — значит и wantsJson() не нужен.
     */
    public function index(Request $request): Response {
        // Лайк принадлежит профилю, а не пользователю (likeables.profile_id → profiles.id),
        // поэтому везде ниже работаем с id профиля.
        $profileId = $request->user()->profile?->id;

        $posts = Post::query()
            // Клиент видит только опубликованное. Посты на модерации — забота админки;
            // своё «на модерации» автор увидит на странице «Мои публикации» (п. 12).
            ->where('status', Post::STATUS_PUBLISHED)
            // author и category грузим заранее — иначе на десять карточек ленты
            // получим двадцать лишних запросов (N+1).
            ->with(['author', 'category'])
            // Счётчик лайков одним подзапросом. Атрибут приедет как liked_by_profiles_count,
            // наружу PostResource отдаст его как likes_count.
            ->withCount('likedByProfiles')
            // Признак «этот пост лайкнул я» — разбор в п. 6.
            ->withExists([
                'likedByProfiles as is_liked' => fn (Builder $query) => $query->whereKey($profileId),
            ])
            // NULLS LAST, а не просто latest('published_at'): в PostgreSQL сортировка
            // по убыванию по умолчанию ставит NULL первыми, и посты без даты публикации
            // оказались бы вверху ленты. В базе проекта такие есть.
            ->orderByRaw('published_at DESC NULLS LAST')
            // Второй ключ обязателен: published_at может совпасть у постов, созданных
            // сидером в одну секунду, а offset без строгого порядка позволяет одной
            // записи приехать сразу на двух страницах (грабля из 20-го урока).
            ->latest('id')
            ->paginate(10);

        return inertia('Client/Feed/Index', [
            // Та же упаковка, что в админке: { data, links, meta }. Форма ответа
            // у пагинации одна на всё приложение, и Vue-страницы читают её одинаково.
            'posts' => PostResource::collection($posts)->response()->getData(true),
        ]);
    }
}
```

`$request->user()->profile?->id` с оператором `?->`: у пользователя, заведённого через регистрацию Breeze, профиля может не быть — его создаёт сидер или отдельная форма. В ленте это безопасно (без профиля просто ничего не будет отмечено лайкнутым), а вот в `toggleLike()` отсутствие профиля придётся обработать явно (п. 9).

## 6. Признак «я лайкнул»: `withExists()` и `whenHas()`

Лайк — это строка в `likeables`, а не колонка в `posts`. Значит вопрос «лайкнул ли текущий профиль этот пост» — вопрос о существовании связанной записи. Наивный ответ — загрузить лайки каждого поста и искать в них себя; правильный — спросить у базы одним подзапросом на весь запрос:

```php
->withExists([
    'likedByProfiles as is_liked' => fn (Builder $query) => $query->whereKey($profileId),
])
```

Что здесь происходит:

- **`withExists()` — брат `withCount()`.** Оба добавляют к `SELECT` коррелированный подзапрос: `withCount` возвращает число, `withExists` — булево `EXISTS(...)`. Дополнительных запросов не будет ни одного: значение приезжает вместе со строкой поста.
- **`as is_liked` — алиас.** Без него атрибут назывался бы `liked_by_profiles_exists`. Алиас нужен ещё и потому, что имя `is_liked` мы объявляем контрактом наружу — в ресурсе и во Vue.
- **Замыкание сужает подзапрос до одного профиля.** Без него получилось бы «пост лайкнул хоть кто-нибудь». `$query` внутри — билдер по `Profile`, поэтому `whereKey()` подставит `profiles.id`.
- **`whereKey(null)` не падает.** Если профиля нет, сравнение с `NULL` в SQL не истинно ни для одной строки, и `is_liked` придёт `false` у всех постов. Ровно то поведение, которое нужно.

Дальше значение нужно отдать клиенту. `PostResource` дополняется двумя полями:

IN `app/Http/Resources/Post/PostResource.php`:
```php
'likes_count' => $this->whenCounted('likedByProfiles'),
// whenHas — «отдай ключ, только если такой атрибут вообще есть у модели».
// В админском списке withExists() не вызывается, атрибута нет, и ключ is_liked
// в ответе не появится — как whenLoaded для связей и whenCounted для счётчиков.
//
// Приведение к bool обязательно: PostgreSQL возвращает настоящий boolean, а MySQL
// отдал бы 1/0, и на клиенте пришлось бы помнить, что «1» — это истина.
'is_liked' => $this->whenHas('is_liked', fn (mixed $value): bool => (bool) $value),
// Ленте нужно имя автора, а не только author_id. Связь та же, что в админке,
// просто там она не грузилась.
'author' => $this->whenLoaded(
    'author',
    fn (Profile $author): array => ProfileResource::make($author)->resolve(),
),
```

Важно, что ресурс остался один на всё приложение. Не «клиентский PostResource» и не «ресурс ленты»: набор ключей меняется не классом ресурса, а тем, что контроллер успел загрузить. Это и есть смысл `whenLoaded`/`whenCounted`/`whenHas` — один ресурс обслуживает разные экраны, не отдавая лишнего и не провоцируя лишних запросов.

## 7. `Client/Feed/Index.vue` — карточки и пагинация ссылками

IN `resources/js/Pages/Client/Feed/Index.vue`:
```vue
<template>
    <Head title="Лента" />

    <h1 class="mb-4 text-2xl font-semibold text-gray-900">Лента</h1>

    <p v-if="!posts.data.length" class="bg-white p-6 text-sm text-gray-500">
        Публикаций пока нет.
    </p>

    <article
        v-for="post in posts.data"
        :key="post.id"
        class="mb-4 rounded-lg bg-white p-5 shadow"
    >
        <p class="mb-1 text-xs uppercase tracking-wider text-gray-400">
            {{ post.author?.nickname ?? 'Аноним' }} · {{ post.category?.title ?? 'Без категории' }}
        </p>

        <Link
            :href="route('client.posts.show', post.id)"
            class="text-lg font-semibold text-gray-900 hover:text-sky-700"
        >
            {{ post.title }}
        </Link>

        <p class="mt-2 text-sm text-gray-700">{{ excerpt(post.content) }}</p>

        <!--
            Лайк в ленте — только индикатор, без клика: ставится он на странице поста.
            Так лента остаётся страницей чтения, а не набором кнопок, меняющих данные.
        -->
        <p class="mt-3 text-sm" :class="post.is_liked ? 'text-rose-600' : 'text-gray-400'">
            ♥ {{ post.likes_count }}
        </p>
    </article>

    <!--
        Пагинация ссылками, а не axios: в ленте нет фильтра, поэтому и локального
        состояния запроса нет — достаточно перейти на /feed?page=2. Link делает
        Inertia-переход, сервер отдаёт новые пропсы, раскладка остаётся на месте.

        link.url, а не link.page: в отличие от админки, номер страницы нам никуда
        подставлять не нужно — пагинатор уже собрал готовый URL. У «...» и у неактивных
        стрелок url равен null, поэтому такие элементы показываем span'ом.

        preserve-scroll не ставим сознательно: при переходе на следующую страницу
        пользователь должен оказаться вверху списка, а не там, где кликнул.
    -->
    <nav v-if="posts.meta.last_page > 1" class="mt-6 flex flex-wrap gap-1">
        <template v-for="(link, index) in posts.meta.links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
                class="border px-3 py-2 text-sm"
                :class="link.active ? 'border-sky-800 bg-sky-700 text-white' : 'border-gray-300 bg-white'"
                v-html="link.label"
            />
            <span v-else class="border border-gray-200 px-3 py-2 text-sm text-gray-300" v-html="link.label" />
        </template>
    </nav>
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Index',
    layout: ClientLayout,
    components: { Head, Link },
    props: {
        posts: {
            type: Object,
            default: () => ({ data: [], meta: { links: [], last_page: 1 } }),
        },
    },
    methods: {
        /**
         * Короткий анонс поста. Обрезаем на клиенте, потому что на странице поста
         * нужен полный текст, и второй выборки ради превью делать не хочется.
         */
        excerpt(content, length = 200) {
            return content.length > length ? `${content.slice(0, length)}…` : content;
        },
    },
};
</script>
```

Здесь важен контраст с админским списком. Там список — это локальная копия пропса (`postsData`), которую axios заменяет на каждый чих фильтра. Тут страница читает `posts` напрямую из пропса и никогда его не меняет: новая страница ленты — это новый ответ сервера и новые пропсы. Локальная копия нужна только там, где клиент правит данные сам.

## 8. `PostController::show()` — страница поста

IN `app/Http/Controllers/Client/PostController.php`:
```php
<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Post\PostResource;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Response;

class PostController extends Controller {
    /**
     * Страница отдельного поста.
     *
     * Модель приезжает из route model binding — несуществующий id даёт 404 до контроллера.
     */
    public function show(Request $request, Post $post): Response {
        $profileId = $request->user()->profile?->id;

        // Черновик и пост на модерации по прямой ссылке не показываем — но автору
        // свой пост открыть можно. 404, а не 403: существование чужого неопубликованного
        // поста — тоже информация, и подтверждать её незачем.
        //
        // Правильное место для такого правила — PostPolicy::view(), политики идут дальше
        // по курсу. Пока проверка живёт в контроллере, и это осознанный временный шаг.
        abort_unless(
            $post->status === Post::STATUS_PUBLISHED || $post->author_id === $profileId,
            404,
        );

        // load/loadCount/loadExists — это with/withCount/withExists для уже полученной
        // модели: запрос строил контейнер, достроить его мы не можем, поэтому догружаем
        // отдельными запросами. Без них PostResource промолчит про связи и про лайки —
        // whenLoaded/whenCounted/whenHas просто не найдут данных.
        $post->load(['author', 'category', 'images', 'tags']);
        $post->loadCount('likedByProfiles');
        $post->loadExists([
            'likedByProfiles as is_liked' => fn (Builder $query) => $query->whereKey($profileId),
        ]);

        return inertia('Client/Post/Show', [
            'post' => PostResource::make($post)->resolve(),
        ]);
    }
```

## 9. Лайк: `toggle()` на связи и что он возвращает

Связь уже есть с 12-го урока: `Post::likedByProfiles()` — это `morphToMany(Profile::class, 'likeable')->withTimestamps()`. Ставить и снимать лайк можно тремя способами:

```php
$post->likedByProfiles()->attach($profileId);  // поставить
$post->likedByProfiles()->detach($profileId);  // снять
$post->likedByProfiles()->toggle($profileId);  // переключить
```

`toggle()` смотрит, есть ли уже строка в `likeables`, и делает противоположное: есть — удаляет, нет — вставляет. Именно это поведение и нужно кнопке-сердечку, поэтому клиенту не приходится решать, какой запрос слать.

Что он возвращает — массив с двумя ключами:

```php
['attached' => [7], 'detached' => []]   // лайк поставили
['attached' => [], 'detached' => [7]]   // лайк сняли
```

Это готовый ответ на вопрос «а что в итоге произошло» — дополнительный `exists()`-запрос не нужен. `withTimestamps()` на связи означает, что при вставке заполнятся `created_at`/`updated_at` в `likeables`: потом по ним можно будет строить «кто лайкнул последним».

Внутри `toggle()` — два действия: сначала `SELECT` уже привязанных id, потом `DELETE` или `INSERT`. Транзакции между ними нет, поэтому два одновременных запроса от одного профиля теоретически могут разойтись (см. граблю про 23505). Рядом лежит `toggleOrFail()` — тот же метод, обёрнутый в `DB::transaction()`; для одного пользователя с одной кнопкой разница неощутима, и в уроке остаётся простой `toggle()`.

Второй метод того же `Client\PostController` — рядом с `show()`:

```php
    /**
     * Переключение лайка на посте.
     *
     * Возвращает массив, а не Inertia-страницу: запрос уходит от axios, страница остаётся
     * на месте, обновить нужно только сердечко и счётчик.
     *
     * @return array<string, mixed>
     */
    public function toggleLike(Request $request, Post $post): array {
        $profile = $request->user()->profile;

        // Лайк принадлежит профилю, и без профиля операция невозможна. 403 с текстом —
        // честнее, чем 500 от обращения к null.
        abort_if($profile === null, 403, 'У пользователя нет профиля.');

        $changes = $post->likedByProfiles()->toggle($profile->id);

        return [
            // Непустой attached означает, что строку вставили, то есть лайк теперь стоит.
            'is_liked' => $changes['attached'] !== [],
            // Счётчик считаем после переключения: клиенту нужно актуальное число,
            // а не то, что приехало с последней загрузкой страницы. Один count(*)
            // по likeables — дёшево.
            'likes_count' => $post->likedByProfiles()->count(),
        ];
    }
}
```

FormRequest здесь не нужен: тела у запроса нет вообще, всё, что требуется, приезжает из URL (`{post}`) и из сессии (текущий пользователь). Валидировать нечего — а значит и класс заводить не за чем.

Сервис (`LikeService`) тоже пока не заводим. В `PostService` логика переехала потому, что создание поста — это транзакция из трёх операций, которая нужна и контроллеру, и API. Здесь — одна строка `toggle()`, и вынести её в сервис значило бы получить класс, который ничего не упрощает.

## 10. `Client/Post/Show.vue` — пост и кнопка лайка

IN `resources/js/Pages/Client/Post/Show.vue`:
```vue
<template>
    <Head :title="postData.title" />

    <Link :href="route('client.feed.index')" class="mb-4 inline-block text-sm text-sky-700">
        ← В ленту
    </Link>

    <article class="rounded-lg bg-white p-6 shadow">
        <h1 class="mb-2 text-2xl font-semibold text-gray-900">{{ postData.title }}</h1>

        <p class="mb-4 text-sm text-gray-500">
            {{ postData.author?.nickname ?? 'Аноним' }} ·
            {{ postData.category?.title ?? 'Без категории' }}
        </p>

        <div v-if="postData.images?.length" class="mb-4 grid grid-cols-3 gap-2">
            <img
                v-for="image in postData.images"
                :key="image.id"
                :src="image.url"
                :alt="postData.title"
                class="h-40 w-full rounded object-cover"
            />
        </div>

        <p class="whitespace-pre-line text-gray-700">{{ postData.content }}</p>

        <ul v-if="postData.tags?.length" class="mt-4 flex flex-wrap gap-2">
            <li
                v-for="tag in postData.tags"
                :key="tag.id"
                class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600"
            >
                #{{ tag.title }}
            </li>
        </ul>

        <!--
            Кнопка, а не ссылка: действие меняет данные. type="button" обязателен —
            внутри формы кнопка по умолчанию сабмитит её.

            :disabled на время запроса — не косметика: в likeables стоит
            unique(profile_id, likeable_type, likeable_id), и два быстрых клика подряд
            могут разойтись в гонке и уронить вставку с нарушением уникальности.

            aria-pressed сообщает скринридеру состояние переключателя: у иконки-сердечка
            нет текста, по которому это было бы понятно.
        -->
        <button
            type="button"
            :disabled="isLikePending"
            :aria-pressed="postData.is_liked"
            class="mt-6 inline-flex items-center gap-2 text-sm disabled:opacity-50"
            :class="postData.is_liked ? 'text-rose-600' : 'text-gray-400 hover:text-rose-500'"
            @click="toggleLike"
        >
            <!--
                Одна иконка на оба состояния: заливка переключается атрибутом fill.
                currentColor означает «цвет текста кнопки» — цвет задаёт класс выше,
                и SVG о нём ничего не знает.
            -->
            <svg
                class="h-6 w-6"
                viewBox="0 0 24 24"
                :fill="postData.is_liked ? 'currentColor' : 'none'"
                stroke="currentColor"
                stroke-width="1.5"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"
                />
            </svg>

            <span>{{ postData.likes_count }}</span>
        </button>
    </article>
</template>

<script>
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link },
    props: {
        post: {
            type: Object,
            required: true,
        },
    },
    data() {
        return {
            // Локальная копия пропса: после лайка меняются is_liked и likes_count,
            // а пропсы во Vue односторонние — писать в них нельзя. Копия поверхностная
            // ({ ...this.post }), и этого достаточно: мы меняем только два скалярных поля,
            // а вложенные images/tags не трогаем.
            postData: { ...this.post },
            // Блокировка кнопки на время запроса — от двойных кликов.
            isLikePending: false,
        };
    },
    methods: {
        toggleLike() {
            this.isLikePending = true;

            // Тела у запроса нет — второй аргумент axios.post() не нужен вовсе.
            // CSRF-заголовок axios подставит сам из куки XSRF-TOKEN: запрос уходит
            // на свой домен, а маршрут лежит в группе web.
            axios
                .post(route('client.posts.likes.toggle', this.postData.id))
                .then((res) => {
                    // Оба значения берём из ответа, а не считаем на клиенте: пока страница
                    // была открыта, пост могли лайкнуть другие, и локальный ++ разошёлся бы
                    // с базой. Сервер — единственный источник правды (тот же вывод,
                    // что в 21-м уроке про перезапрос списка после удаления).
                    this.postData.is_liked = res.data.is_liked;
                    this.postData.likes_count = res.data.likes_count;
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isLikePending = false;
                });
        },
    },
};
</script>
```

## 11. `ProfileController::personal()` — свои публикации

IN `app/Http/Controllers/Client/ProfileController.php`:
```php
<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Post\PostResource;
use App\Http\Resources\Profile\ProfileResource;
use Illuminate\Http\Request;
use Inertia\Response;

class ProfileController extends Controller {
    /**
     * Личная страница: профиль и лента собственных публикаций.
     */
    public function personal(Request $request): Response {
        $profile = $request->user()->profile;

        abort_if($profile === null, 404);

        // Запрос строим от связи, а не от Post::query()->where('author_id', ...):
        // profiles → posts уже описана в модели, и условие по author_id она подставит сама.
        //
        // Фильтра по статусу здесь нет намеренно: свой пост на модерации автор видеть должен —
        // иначе он решит, что публикация пропала.
        $posts = $profile->posts()
            ->with('category')
            ->withCount('likedByProfiles')
            ->latest('id')
            ->paginate(10);

        return inertia('Client/Profile/Personal', [
            'profile' => ProfileResource::make($profile)->resolve(),
            'posts' => PostResource::collection($posts)->response()->getData(true),
        ]);
    }
}
```

Выборка похожа на ленту, но повторение пока не выносим: наборы условий разные (там статус и `withExists`, тут все статусы и своя сортировка), и общий метод пришлось бы сразу параметризовать. Правило то же, что с `CategoryResource::collection(Category::all())` в админке: два похожих места — ещё не дублирование.

`Client/Profile/Personal.vue` — это `Feed/Index.vue` с шапкой профиля и статусом у каждого поста:

```vue
<template>
    <Head title="Мои публикации" />

    <section class="mb-6 rounded-lg bg-white p-5 shadow">
        <h1 class="text-2xl font-semibold text-gray-900">{{ profile.nickname }}</h1>
        <p class="text-sm text-gray-500">
            {{ [profile.first_name, profile.second_name].filter(Boolean).join(' ') || 'Имя не заполнено' }}
        </p>
        <p class="mt-2 text-sm text-gray-500">Публикаций: {{ posts.meta.total }}</p>
    </section>

    <article v-for="post in posts.data" :key="post.id" class="mb-4 rounded-lg bg-white p-5 shadow">
        <Link :href="route('client.posts.show', post.id)" class="text-lg font-semibold text-gray-900">
            {{ post.title }}
        </Link>

        <!-- Свой пост на модерации видно, и об этом лучше сказать прямо. -->
        <span v-if="post.status !== 1" class="ml-2 rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">
            На модерации
        </span>

        <p class="mt-2 text-sm text-gray-700">{{ excerpt(post.content) }}</p>
        <p class="mt-3 text-sm text-gray-400">♥ {{ post.likes_count }}</p>
    </article>
</template>
```

`post.status !== 1` — сравнение с магическим числом: константа `Post::STATUS_PUBLISHED` живёт в PHP, а на клиент приезжает голая единица. Это заметный шов между сервером и клиентом, и способ его убрать — отдавать из ресурса не только код, но и подпись статуса (см. п. 15).

## 12. Почему лента не кэшируется

В 21-м уроке админский список постов уехал в `Cache::remember()` на два часа, и в разделе «что можно сделать лучше» было сказано: кэш нужен как раз публичной ленте, а не админке. Ленту мы всё-таки не кэшируем — и причина в том, что она персонализирована.

Ключ кэша админского списка собирается из параметров запроса: `posts_index_<версия>_<md5 фильтров>`. Для ленты того же набора мало — в ответе есть `is_liked`, и он у каждого пользователя свой. Положив ленту в общий ключ, мы отдали бы второму зашедшему чужие сердечки. Значит в ключ обязан войти id профиля — и кэш немедленно теряет смысл: он перестаёт быть общим, а на своих собственных данных каждый пользователь будет собирать его заново.

Второе — инвалидация. Версия `posts_index_version` растёт при сохранении и удалении поста (`PostService::bumpIndexVersion()`), но лайк постов не трогает: он пишет в `likeables`. Счётчик `likes_count` в закэшированной ленте застыл бы на два часа.

Правильный путь для ленты — кэшировать не персонализированную часть (список постов и счётчики) и накладывать «мои лайки» поверх отдельным дешёвым запросом. Это уже не тема этого урока, но именно так решается конфликт между кэшем и персонализацией.

## 13. Проверка

Отдельного теста в уроке нет, но убедиться, что всё сошлось, стоит по шагам:

1. `php artisan route:list --path=feed` и `--path=posts` — маршруты `client.*` есть и лежат в группе `web`, `auth`. Если группы нет, `then:` в `bootstrap/app.php` подключил файл мимо `Route::middleware('web')`.
2. `/feed` открывается, в шапке видно имя пользователя (значит `share()` доехал), карточки показывают автора и категорию.
3. В `php artisan pail` или в логе запросов на страницу ленты уходит немного запросов, а не «по два на карточку» — работает `with(['author', 'category'])`.
4. Клик по сердечку меняет и заливку, и число; обновление страницы (F5) показывает то же состояние — значит лайк лёг в базу, а не только в память вкладки.
5. Второй клик снимает лайк, число возвращается — это и есть `toggle()`.
6. `/profiles/personal` показывает свои посты, включая те, что на модерации.

## 14. Грабли

- **`Route [client.feed.index] not defined`** — Ziggy отдаёт список маршрутов директивой `@routes` в момент полной загрузки страницы. После добавления `routes/client.php` открытую вкладку нужно перезагрузить целиком (F5), SPA-переход новых имён не увидит.
- **Страница отдаётся как JSON-мусор, `auth()` пуст, POST даёт 419** — файл маршрутов подключён без `Route::middleware('web')`. Без этой группы нет ни сессии, ни куки `XSRF-TOKEN`, ни `HandleInertiaRequests`.
- **`Attempt to read property "id" on null`** — у пользователя нет профиля. В ленте спасает `?->`, в `toggleLike()` нужен явный `abort_if()`.
- **`is_liked` не приходит на клиент** — забыли `withExists()` (или `loadExists()` на странице поста). `whenHas()` молча не отдаёт ключ, которого нет у модели; во Vue `post.is_liked` станет `undefined`, а сердечко — всегда пустым.
- **Лайкнутым оказывается всё подряд** — в `withExists()` не передано замыкание, и подзапрос отвечает на вопрос «лайкнул ли хоть кто-нибудь».
- **Вверху ленты висят посты без даты публикации** — в PostgreSQL `ORDER BY published_at DESC` ставит `NULL` первыми (`NULLS FIRST` — поведение по умолчанию для `DESC`), а `latest('published_at')` строит именно такой запрос. Поэтому в ленте стоит `orderByRaw('published_at DESC NULLS LAST')`; альтернатива — сделать `published_at` не-nullable и заполнить старые записи.
- **Один и тот же пост виден на двух страницах ленты** — сортировка по неуникальному полю. Второй ключ (`latest('id')`) делает порядок строгим.
- **405 Method Not Allowed на лайке** — `axios.get()` вместо `axios.post()` либо маршрут объявлен как `Route::get`. Ziggy отдаёт только URL и про метод ничего не знает.
- **`SQLSTATE[23505] duplicate key value violates unique constraint`** — два клика по сердечку разошлись в гонке: оба запроса увидели «лайка нет» и оба попытались вставить строку. `:disabled` на время запроса убирает 99% случаев; полностью — только `insertOrIgnore`/`upsert` или обработка исключения.
- **Vue ругается «Set operation on key ... failed: target is readonly» / «Avoid mutating a prop directly»** — пишем в `post` вместо локальной копии `postData`.
- **Счётчик в ленте не изменился после лайка** — так и задумано: лента получила свои данные при загрузке. Свежие числа приедут при следующем переходе на неё.
- **`Page not found: Client/Feed/Index`** — путь в `inertia()` не совпал с путём файла в `resources/js/Pages`. Строка чувствительна к регистру, а `.vue` в ней не пишется.
- **Страница белая, в консоли ошибка Vite** — новый `.vue`-файл появился после старта дев-сервера. `app.blade.php` подключает компонент страницы через `@vite`, поэтому `npm run dev` (или `composer run dev`) нужно перезапустить.

## 15. Что можно сделать лучше

Ниже — не задачи урока, а направления, куда эта реализация растёт.

**Подписки.** Ради них лента и затевалась. Таблица `subscriptions` (`subscriber_id`, `author_id`, оба — `profiles.id`), связи `Profile::subscriptions()` и `Profile::subscribers()`, а в `FeedController::index()` — одно дополнительное условие: `->whereIn('author_id', $profile->subscriptions()->pluck('profiles.id'))` или, лучше, `whereHas`. Всё остальное в уроке останется как есть.

**Отдельный `Client\LikeController`.** Сейчас лайк живёт в `PostController` методом `toggleLike()` — это ещё не перегруз, но лайкать в соцсети можно не только посты: связь `likeables` полиморфная, и завтра те же кнопки появятся у комментариев и изображений. Тогда либо контроллер лайков с полиморфным параметром маршрута, либо по методу в контроллере каждого ресурса — и вот в этот момент общая логика («найти профиль, переключить связь, вернуть счётчик») уже заслуживает `LikeService`.

**Ресурсные `store`/`destroy` вместо `toggle`.** Строгий REST развёл бы постановку и снятие лайка по двум маршрутам: `POST posts/{post}/likes` и `DELETE posts/{post}/likes`. Это честнее по семантике HTTP (каждый метод делает ровно одно) и лучше кэшируется прокси, но перекладывает на клиента решение, какой запрос слать, — а он опирается на состояние, которое могло устареть. Для кнопки-переключателя выбран `toggle`; для API, где клиент шлёт осознанные команды, разумнее пара.

**Политика вместо `abort_unless()`.** Проверка «пост опубликован или мой» — классическая `PostPolicy::view()`. В контроллере останется `Gate::authorize('view', $post)`, а правило станет доступно и API, и админке.

**Подпись статуса из ресурса.** `'status_title' => Post::getStatuses()[$this->status] ?? null` уберёт из Vue сравнение с числом `1`. Список статусов уже есть в модели — на клиенте его дублировать незачем.

**Оптимистичное обновление лайка.** Сейчас сердечко перекрашивается после ответа сервера — на медленной сети это заметная задержка. Оптимистичный вариант меняет состояние сразу, а в `.catch()` откатывает. Взамен появляется необходимость помнить предыдущее состояние и аккуратно обрабатывать гонки.

**Бесконечная лента.** Inertia v2 умеет это штатно: `WhenVisible` + merging props вместо кнопок пагинации. Для ленты это естественнее страниц, но требует `cursorPaginate()` — обычный offset при подгрузке ленты даёт сдвиг записей, когда наверху появляется новый пост.

**Комментарии на странице поста.** Модель `Comment` и полиморфная связь `commentable` есть с 12-го урока, но на клиенте не используются. Это ближайший кандидат на следующий шаг: форма ответа, список комментариев, счётчик в карточке ленты через `withCount('comments')`.

**Кэш ленты с разделением персонального и общего.** Общая часть (посты, счётчики) — в `Cache::remember()` с версией, которую двигают и сохранение поста, и лайк; «мои лайки» — отдельным запросом `likeables` по id постов страницы, поверх закэшированного массива.

**Тесты клиентской части.** Feature-тест на ленту (`assertInertia` с проверкой компонента и числа постов), на 404 для чужого неопубликованного поста и на лайк (`assertDatabaseHas('likeables', ...)` после первого запроса и `assertDatabaseMissing(...)` после второго). Последний фиксирует именно то поведение, ради которого выбран `toggle()`.
