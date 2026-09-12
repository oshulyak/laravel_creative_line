# Lesson 27 - Репост: пост, у которого есть родитель

Цель урока: у карточки поста появляется иконка репоста. Клик открывает модальное окно с одним полем — заголовком; кнопка «Repost» отправляет запрос, окно закрывается, а новый пост приезжает в «Мои публикации» и встаёт среди обычных. Клик мимо окна его закрывает.

По пути разбираются: **самоссылающийся внешний ключ** (`posts.parent_id → posts.id`) и пара связей `parent()` / `reposts()` вокруг него; поведение FK при удалении родителя (`nullOnDelete()`) — и чем настоящий внешний ключ отличается от полиморфных связей, с которыми мы работали в 25-26 уроках; **ресурс, который вкладывает сам себя**, и `whenLoaded()` в роли ограничителя рекурсии; `unique` на `title` как настоящая причина, по которой у репоста вообще спрашивают заголовок; и на клиенте — переиспользование готового `Modal.vue` из Breeze, закрытие по клику вне окна и почему оно работает без `@click.stop`.

Отправная точка — состояние после 26-го урока.

## 1. Карта урока

| Файл | Что меняется |
| --- | --- |
| `database/migrations/..._add_parent_id_to_posts_table.php` | новый: колонка `parent_id` |
| `app/Models/Post.php` | `parent_id` в `$fillable`, связи `parent()` и `reposts()` |
| `routes/client.php` | маршрут `client.posts.reposts.store` |
| `app/Http/Requests/Client/Repost/StoreRequest.php` | новый: правила и подстановка полей |
| `app/Http/Controllers/Client/PostController.php` | метод `storeRepost()`, связи и счётчик в `show()` |
| `app/Http/Resources/Post/PostResource.php` | ключи `parent_id`, `parent`, `reposts_count` |
| `app/Http/Controllers/Client/FeedController.php` | `with('parent.author')` + `withCount('reposts')` |
| `app/Http/Controllers/Client/ProfileController.php` | то же самое |
| `resources/js/Components/Post/RepostButton.vue` | новый: иконка, модалка, отправка |
| `resources/js/Components/Post/ItemPost.vue` | кнопка репоста и строка «Репост: …» |
| `resources/js/Pages/Client/Feed/Index.vue` | `provide` обработчика `onPostReposted` |
| `resources/js/Pages/Client/Profile/Personal.vue` | то же самое |
| `resources/js/Pages/Client/Post/Show.vue` | кнопка репоста на странице поста |
| `tests/Feature/ClientRepostTest.php` | новый: пять тестов |

Команды для генерации файлов (запускаете вы):

```
php artisan make:migration add_parent_id_to_posts_table --table=posts --no-interaction
php artisan make:request Client/Repost/StoreRequest --no-interaction
php artisan make:test --phpunit ClientRepostTest --no-interaction
```

Vue-компонент создаётся руками: генератора для него в Laravel нет.

## 2. Что такое репост в этой схеме

Развилка на старте — та же, что была у комментариев: **новая сущность или та же самая с новой ролью**.

1. Отдельная таблица `reposts` со ссылками на пост и на профиль. Тогда репост — не пост: он не попадёт ни в ленту, ни в «Мои публикации», ни в выдачу по автору, пока мы не научим каждый из этих запросов объединять две таблицы.
2. Тот же `posts`, у которого появился необязательный родитель. Репост — обычный пост: со своим автором, заголовком, датой, лайками и комментариями. Отличие ровно одно — заполненный `parent_id`.

Задание прямо указывает второй вариант («добавить в пост `parent_id`»), и он же правильный по существу. Требование «репост должен отображаться в „Мои публикации“ среди обычных постов» при таком выборе выполняется **само**: `ProfileController::personal()` уже выбирает `$profile->posts()`, и репост в этой выборке ничем не выделяется. Ни строчки кода ради этого пункта задания писать не придётся.

Это тот же приём, что в 26-м уроке с ответами: ответ — комментарий с другим родителем, репост — пост с родителем. Разница в механике: там родитель был полиморфным (`commentable_type` + `commentable_id`), здесь родитель всегда пост, поэтому обходимся одной колонкой и настоящим внешним ключом.

**Что копируется в репост.** `posts.content` — `NOT NULL`, значит текст у репоста быть обязан. Копируем текст оригинала в момент создания. Это снимок: оригинал потом отредактируют — репост останется прежним. Альтернатива (хранить пустой `content` и подтягивать текст родителя при показе) требует сделать колонку nullable и научить карточку рисовать два разных случая; для учебного этапа копия проще и честнее. `category_id` копируем по той же причине — иначе в карточке репоста будет «Без категории».

## 3. Миграция: колонка `parent_id`

IN новом файле миграции:
```php
    public function up(): void {
        Schema::table('posts', function (Blueprint $table) {
            // nullable обязателен: parent_id есть только у репостов, а обычных
            // постов в таблице большинство. Колонка без nullable не добавилась бы
            // к непустой таблице вовсе — PostgreSQL потребовал бы значение
            // для существующих строк.
            //
            // constrained('posts') — таблицу указываем явно. Обычно Laravel выводит
            // её из имени колонки (category_id → categories), но parent_id
            // не содержит имени таблицы, и угадывать тут нечего.
            //
            // Это самоссылающийся внешний ключ: и родитель, и ребёнок лежат
            // в одной таблице. Для базы в этом нет ничего особенного.
            $table->foreignId('parent_id')
                ->nullable()
                ->index()
                ->constrained('posts')
                ->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table('posts', function (Blueprint $table) {
            // Сначала снять ограничение, потом убрать колонку — иначе PostgreSQL
            // не даст удалить колонку, на которой висит FK. dropConstrainedForeignId()
            // делает оба шага одним вызовом.
            $table->dropConstrainedForeignId('parent_id');
        });
    }
```

**Про `nullOnDelete()` — это главное решение миграции.** У внешнего ключа есть поведение «что делать с детьми, когда удаляют родителя», и вариантов три:

- **по умолчанию (`RESTRICT`)**: база откажется удалять пост, у которого есть репосты. `PostService::destroy()` упадёт с 500-й, и автор не сможет удалить свою публикацию из-за того, что кто-то чужой её репостнул;
- **`cascadeOnDelete()`**: вместе с оригиналом исчезнут все репосты. Удаляя свой пост, автор стёр бы чужие публикации — так нельзя;
- **`nullOnDelete()`**: репост остаётся, `parent_id` становится `NULL`. Репост превращается в обычный пост, ссылка «Репост: …» в карточке пропадает.

Берём третий. Общее правило: **удаление родителя не должно ни ломать операцию, ни удалять чужое**.

Здесь же видна разница с полиморфными связями. У `images`, `taggables`, `likeables` и `comments` внешних ключей нет — у строки там не один возможный родитель, и `FOREIGN KEY` невозможен; поэтому уборку за удалённым постом `PostService::destroy()` делает руками. С `parent_id` родитель ровно один, ключ настоящий — и обнуление делает сама база. В `PostService` менять ничего не нужно.

Порядок колонок в PostgreSQL не настраивается: `->after('author_id')` понимает только MySQL, Postgres молча его игнорирует и ставит колонку в конец. Не пишем его вовсе, чтобы не обещать того, чего не будет.

## 4. Модель: две связи вокруг одной колонки

IN `app/Models/Post.php` — в `$fillable` добавляется `'parent_id'` (после `'author_id'`), и добавляются две связи:

```php
    /**
     * Оригинал, с которого сделан репост (posts.parent_id → posts.id).
     *
     * Имя колонки указываем вторым аргументом: по имени связи parent Eloquent
     * искал бы parent_id — угадал бы, — но по имени модели Post он ждёт post_id.
     * Явное указание снимает вопрос совсем.
     *
     * У обычного поста связь вернёт null. Nullable-колонка — nullable-связь,
     * и на клиенте это отзовётся `?.` при обращении к parent.
     */
    public function parent(): BelongsTo {
        return $this->belongsTo(Post::class, 'parent_id');
    }

    /**
     * Репосты этой публикации.
     *
     * hasMany, а не morphMany: у репоста родитель всегда пост, тип хранить негде
     * и незачем. Пара parent()/reposts() — две стороны одной колонки: belongsTo
     * смотрит «вверх», hasMany — «вниз».
     */
    public function reposts(): HasMany {
        return $this->hasMany(Post::class, 'parent_id');
    }
```

Добавляется импорт `use Illuminate\Database\Eloquent\Relations\HasMany;`.

`parent_id` в `$fillable` нужен не для формы — клиент его не присылает, — а для `$post->reposts()->create([...])`: связь подставляет `parent_id` сама, но кладёт его через массовое присвоение. Без записи в `$fillable` значение будет отброшено молча, и в базе окажется обычный пост без родителя: колонка nullable, ошибки не будет. Молчаливая ошибка хуже громкой — отсюда проверка `parent_id` в тесте (п. 11).

## 5. Маршрут

IN `routes/client.php` — в ту же группу `auth`, рядом с маршрутами поста:
```php
    // Репост публикации. Адрес вложен в оригинал: репост не бывает сам по себе,
    // он всегда «репост чего-то» — та же форма, что у posts/{post}/comments.
    //
    // Последний сегмент — reposts, хотя модель та же самая Post. В URL называем
    // РОЛЬ, а не класс: posts/5/posts читалось бы как опечатка. Ровно тот же
    // приём, что с replies в прошлом уроке.
    //
    // Только store: списка репостов и их удаления в задании нет. Маршруты
    // заводим под то, что реально нужно, а не «на вырост».
    Route::post('posts/{post}/reposts', [PostController::class, 'storeRepost'])
        ->whereNumber('post')
        ->name('client.posts.reposts.store');
```

Отдельный `RepostController` не заводим по той же причине, что и `ReplyController` в 26-м: ресурс здесь один — пост, и метод работает с той же моделью и тем же ресурсом, что и соседи.

## 6. `StoreRequest`: зачем вообще спрашивать заголовок

Самое интересное в этом уроке место, и оно не на клиенте.

В миграции `posts` стоит `$table->string('title')->unique()`. Значит, скопировать заголовок оригинала нельзя: второй репост той же публикации упрётся в нарушение уникальности, да и первый займёт чужое название. Поэтому модалка в задании спрашивает заголовок — это не украшение интерфейса, а прямое следствие схемы. Правило `unique:posts,title` превращает потенциальную 500-ю от PostgreSQL в понятный 422 с текстом под полем.

IN `app/Http/Requests/Client/Repost/StoreRequest.php`:
```php
<?php

namespace App\Http\Requests\Client\Repost;

use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Генератор ставит здесь false — это топ-1 причина внезапного 403 при отправке формы.
     */
    public function authorize(): bool {
        return true;
    }

    /**
     * Из модалки приходит только title. Остальное подставляет prepareForValidation(),
     * но правила у этих полей настоящие: значение, пришедшее от сервера, тоже стоит
     * проверить — опечатка в коде поймается на 422, а не на 500 из базы.
     *
     * Отдельный неймспейс Repost, а не Client\Post: у репоста свой контракт формы —
     * одно поле вместо картинок, тегов и категории. В 26-м уроке FormRequest ответа
     * переиспользовали именно потому, что контракт совпадал; здесь он другой.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // unique — из-за индекса в схеме; max:255 — из-за string() без длины.
            'title' => ['required', 'string', 'max:255', 'unique:posts,title'],
            'content' => ['required', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'author_id' => ['required', 'integer', 'exists:profiles,id'],
            'published_at' => ['required', 'date'],
        ];
    }

    /**
     * Подмешиваем поля, которых нет в форме, до запуска валидации.
     *
     * $this->route('post') отдаёт ту же модель Post, что приедет в контроллер:
     * неявную привязку Laravel выполняет до FormRequest, и повторного запроса
     * в базу здесь не будет.
     *
     * content и category_id копируются из оригинала — см. п. 2. author_id берём
     * из сессии: подставить чужой id в тело запроса ничто не мешает, но merge()
     * перетрёт его раньше, чем правила его увидят.
     *
     * status не подставляем: у колонки есть DEFAULT (Post::STATUS_PUBLISHED),
     * и админская форма создания поста поступает так же. Репост появляется
     * опубликованным сразу — модерации у клиентских публикаций пока нет.
     */
    protected function prepareForValidation(): void {
        /** @var Post $post */
        $post = $this->route('post');

        $this->merge([
            'content' => $post->content,
            'category_id' => $post->category_id,
            'author_id' => $this->user()?->profile?->id,
            'published_at' => now(),
        ]);
    }
}
```

Чего в `merge()` нет: `parent_id`. Его проставит связь — `$post->reposts()->create(...)` знает id родителя. Тот же принцип, что с `commentable_id` у комментария: не заводить второй источник правды.

## 7. Контроллер

IN `app/Http/Controllers/Client/PostController.php` — новый метод:
```php
    /**
     * Репост публикации.
     *
     * Репостить можно только опубликованное. Свой пост на модерации автор открыть
     * может (см. show()), но репост из него сделал бы черновик публичным в обход
     * модерации — поэтому проверяем именно статус, без поблажки автору.
     *
     * 404, а не 403: наружу это выглядит как «репостить нечего».
     */
    public function storeRepost(StoreRequest $request, Post $post): JsonResponse {
        abort_unless($post->status === Post::STATUS_PUBLISHED, 404);

        $post->reposts()->create($request->validated());

        // Возвращаем не созданный пост, а состояние оригинала: карточка репоста
        // на этой странице не появляется — он уедет в «Мои публикации», — а вот
        // счётчик под иконкой обновить нужно. Тот же формат, что у toggleLike():
        // маленький массив вместо ресурса.
        //
        // Считаем запросом в базу, а не ++ на клиенте: пока страница была открыта,
        // репостнуть мог кто-то ещё.
        return response()->json([
            'reposts_count' => $post->reposts()->count(),
        ], 201);
    }
```

Добавляются импорты `use App\Http\Requests\Client\Repost\StoreRequest;` и `use Illuminate\Http\JsonResponse;`.

IN тот же файл, метод `show()` — странице поста тоже нужны родитель и счётчик:
```php
        $post->load(['author', 'category', 'images', 'tags', 'parent.author']);
        $post->loadCount(['likedByProfiles', 'reposts']);
```

`parent.author` — точечная запись для вложенной связи: «загрузи родителя, а у родителя — автора». Без неё в строке «Репост: …» не будет ника автора оригинала.

**Репост репоста мы не запрещаем** — и это осознанное отличие от «одного уровня» в 26-м уроке. Там глубина ломала интерфейс: ветка уезжала вправо, а дерево потребовало бы рекурсивной загрузки. Здесь цепочка ничего не ломает: у каждого репоста свой автор, свой заголовок и своя карточка, а `parent_id` указывает на непосредственный источник. Запрет стоил бы одной строки, но ввёл бы правило, которого задание не требует.

## 8. `PostResource`: ресурс, который вкладывает сам себя

IN `app/Http/Resources/Post/PostResource.php` — рядом с `author_id`:
```php
            // Отдаём и id, и вложенный объект. id нужен всегда и стоит ноль запросов;
            // объект приедет, только если связь загрузили.
            'parent_id' => $this->parent_id,
```

рядом с `likes_count`:
```php
            // Счётчик репостов — близнец likes_count. Имя атрибута ресурс выведет сам:
            // reposts → reposts_count, псевдоним не нужен.
            'reposts_count' => $this->whenCounted('reposts'),
```

и рядом с `author`:
```php
            // Ресурс вкладывает сам себя — законный приём, ровно как рекурсивный
            // ItemComment в прошлом уроке. И ограничитель тот же по смыслу:
            // у родителя связь parent НЕ загружена, whenLoaded() вернёт MissingValue,
            // и рекурсия остановится на первом уровне. Напиши мы здесь
            // load('parent') — получили бы бесконечный спуск по цепочке репостов.
            //
            // whenLoaded безопасен и для загруженного null (оригинал удалён,
            // parent_id обнулён): замыкание в этом случае не вызывается,
            // в JSON приедет parent: null.
            'parent' => $this->whenLoaded(
                'parent',
                fn (Post $parent): array => PostResource::make($parent)->resolve(),
            ),
```

Добавляется импорт `use App\Models\Post;`.

## 9. Где догрузить связь и счётчик

Карточка `ItemPost` одна на ленту и на «Мои публикации», значит данные ей нужны одинаковые — иначе на одной странице репост будет подписан, а на другой выглядеть обычным постом.

IN `app/Http/Controllers/Client/FeedController.php`, в `index()`:
```php
            ->with(['author', 'category', 'parent.author'])
            ->withCount(['likedByProfiles', 'reposts'])
```

IN `app/Http/Controllers/Client/ProfileController.php`, в `personal()` — те же две строки:
```php
            ->with(['author', 'category', 'parent.author'])
            ->withCount(['likedByProfiles', 'reposts'])
```

Это по-прежнему константное число запросов на страницу: `with()` добавляет два запроса на весь список (родители и их авторы), `withCount()` — подзапрос внутри основного SELECT.

## 10. Клиент

### 10.1. `RepostButton.vue` — иконка, модалка, отправка

IN новом файле `resources/js/Components/Post/RepostButton.vue`:
```vue
<template>
    <!--
        Корень один: кнопка и модалка — два узла, и «лишним» атрибутам родителя
        иначе некуда приземлиться. Обёртка inline-flex, чтобы div не растягивался
        на всю строку футера.
    -->
    <div class="inline-flex">
        <button
            type="button"
            class="inline-flex items-center gap-2 text-gray-400 hover:text-emerald-600"
            @click="openModal"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 0 0-3.7-3.7 48.678 48.678 0 0 0-7.324 0 4.006 4.006 0 0 0-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 0 0 3.7 3.7 48.656 48.656 0 0 0 7.324 0 4.006 4.006 0 0 0 3.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3-3 3"
                />
            </svg>

            <span>{{ count }}</span>
        </button>

        <!--
            Modal.vue пришёл с Breeze и уже лежит в Components — писать своё
            модальное окно незачем. Он умеет ровно то, что просит задание:
            клик по подложке закрывает окно, Escape тоже.

            Почему клик ВНУТРИ окна его не закрывает и @click.stop не нужен:
            подложка и панель в Modal.vue — СОСЕДИ, а не вложенные элементы.
            Клик по панели просто не проходит через подложку, останавливать
            всплытие нечего. Это разница между «перекрыть экран отдельным слоем»
            и «положить окно внутрь затемнённого блока» — во втором случае
            .stop был бы обязателен.

            show — проп, close — событие: окно не закрывает себя само, оно
            сообщает о намерении, а состоянием владеет наш компонент.
        -->
        <Modal :show="isModalShown" max-width="md" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">Репост публикации</h2>

                <p class="mt-1 truncate text-sm text-gray-500">«{{ post.title }}»</p>

                <!--
                    Заголовок обязателен и должен быть свободен: title в posts
                    уникален, и скопировать его у оригинала нельзя (п. 6).

                    @keyup.enter — модификатор клавиши: отправка с клавиатуры
                    без оборачивания в <form>. Формы здесь нет намеренно —
                    вложить её внутрь <dialog> можно, но у диалога своё поведение
                    при submit, и разбираться с ним ради одного поля незачем.
                -->
                <input
                    v-model="title"
                    type="text"
                    maxlength="255"
                    placeholder="Заголовок репоста"
                    class="mt-4 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                    @keyup.enter="submit"
                />

                <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100"
                        @click="closeModal"
                    >
                        Отмена
                    </button>

                    <button
                        type="button"
                        :disabled="isSending || !title.trim()"
                        class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
                        @click="submit"
                    >
                        {{ isSending ? 'Отправляю…' : 'Repost' }}
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>

<script>
import axios from 'axios';
import Modal from '@/Components/Modal.vue';

export default {
    name: 'RepostButton',
    components: { Modal },
    props: {
        // Берём пост целиком, а не готовый url, как у LikeButton и CommentForm.
        // Те обобщались потому, что обслуживают двух разных родителей (пост
        // и комментарий); репостят только пост. Плюс модалке нужен заголовок
        // оригинала — прецедент тот же, что у DeletePost из 24-го урока.
        post: {
            type: Object,
            required: true,
        },
        // Приставка initial — конвенция для «пропса, с которого начинается
        // локальное состояние». default нужен: там, где withCount() не звали,
        // ключа reposts_count в JSON не будет вовсе.
        initialCount: {
            type: Number,
            default: 0,
        },
    },
    emits: ['reposted'],
    data() {
        return {
            isModalShown: false,
            title: '',
            error: '',
            isSending: false,
            count: this.initialCount,
        };
    },
    watch: {
        // Та же причина, что у LikeButton: data() выполняется один раз, а при
        // частичной перезагрузке списка Vue переиспользует экземпляры карточек.
        initialCount(value) {
            this.count = value;
        },
    },
    methods: {
        openModal() {
            this.error = '';
            this.isModalShown = true;
        },
        /**
         * Пока запрос в полёте, окно не закрываем: иначе пользователь не увидит
         * ни ошибки, ни результата, а введённый заголовок потеряется.
         */
        closeModal() {
            if (this.isSending) {
                return;
            }

            this.isModalShown = false;
        },
        submit() {
            this.isSending = true;
            // Старую ошибку убираем до запроса, иначе она провисит до ответа
            // и будет выглядеть реакцией на новую отправку.
            this.error = '';

            axios
                .post(route('client.posts.reposts.store', this.post.id), {
                    title: this.title,
                })
                .then((res) => {
                    // Счётчик берём из ответа, а не считаем на клиенте: сервер —
                    // единственный источник правды.
                    this.count = res.data.reposts_count;
                    // Поле чистим только после успеха: при 422 текст должен
                    // остаться, пользователь его правит, а не набирает заново.
                    this.title = '';
                    this.isModalShown = false;

                    this.$emit('reposted');
                })
                .catch((e) => {
                    // 422 приходит как { message, errors: { title: [...] } }.
                    // Сюда попадает и «Такое название уже занято» — самая частая
                    // ошибка этой формы.
                    this.error = e.response?.data?.errors?.title?.[0]
                        ?? 'Не удалось сделать репост.';
                })
                .finally(() => {
                    this.isSending = false;
                });
        },
    },
};
</script>
```

Компонент отдельный, а не разметка внутри `ItemPost`, — по тому же принципу, что `LikeButton` и `DeletePost`: у него собственное состояние (открыта ли модалка, что набрано, что отправляется), и держать его в карточке значит смешать четыре независимых состояния в одном `data()`.

### 10.2. `ItemPost.vue`: кнопка и подпись «это репост»

IN `resources/js/Components/Post/ItemPost.vue` — абзац с текстом поста заменяется на блок, который у репоста выглядит вложенным. Порядок в карточке: автор и категория, заголовок, затем это:
```vue
        <!--
            Тело карточки. У обычного поста это просто абзац с текстом; у репоста
            тот же абзац получает сдвиг вправо, вертикальную линию слева и мягкую
            подложку — сразу видно, что текст пришёл с чужой публикации.

            Обёртка нужна, чтобы линия и фон охватили и подпись, и текст одним
            блоком: два отдельных элемента со своими рамками разошлись бы
            на отступе между ними.

            Статический class остаётся статическим, а :class добавляет к нему
            оформление вложенности — Vue объединяет оба атрибута, а не заменяет
            один другим.
        -->
        <div
            class="mt-2"
            :class="
                postData.parent
                    ? 'rounded-r-lg border-l-4 border-sky-200 bg-sky-50/60 py-2 pl-4 pr-3'
                    : ''
            "
        >
            <!--
                Строка появляется только у репостов: у обычного поста ключа parent
                в JSON нет вовсе (whenLoaded), и v-if не выполнится.

                Второй случай, который гасит тот же v-if, — удалённый оригинал: тогда
                parent приедет как null, а parent_id как NULL. Репост остаётся обычным
                постом, и подписывать его нечем — вместе с подписью пропадёт и рамка
                с подложкой: :class смотрит на то же самое поле.
            -->
            <p v-if="postData.parent" class="mb-1 text-xs text-gray-500">
                Репост:
                <Link
                    :href="route('client.posts.show', postData.parent.id)"
                    class="text-sky-700 hover:underline"
                >
                    {{ postData.parent.title }}
                </Link>
                · {{ postData.parent.author?.nickname ?? 'Аноним' }}
            </p>

            <p class="whitespace-pre-line text-sm text-gray-700">
                {{ excerpt(postData.content) }}
            </p>
        </div>
```

Разметка здесь чисто оформительская: ни пропов, ни состояния, ни новых компонентов она не добавляет. Признак «это репост» один и тот же — `postData.parent`, — и он управляет и подписью, и рамкой: расходиться этим двум вещам не на чем.

IN тот же файл, в `<footer>` — между `LikeButton` и `DeletePost`:
```vue
            <RepostButton
                :post="postData"
                :initial-count="postData.reposts_count"
                @reposted="handleReposted"
            />
```

IN `<script>` — импорт и регистрация:
```js
import RepostButton from '@/Components/Post/RepostButton.vue';
```
```js
    components: { Link, DeletePost, LikeButton, RepostButton },
```

IN `inject` — второй обработчик рядом с первым:
```js
    inject: {
        onPostDeleted: { default: null },
        onPostReposted: { default: null },
    },
```

IN `methods` — рядом с `handleDeleted`:
```js
        /**
         * Реакция на событие от RepostButton.
         *
         * Карточка снова не решает, что делать, — передаёт факт странице. Тот же
         * приём, что с удалением: на «Моих публикациях» список нужно перезапросить,
         * чтобы свежий репост появился сразу, а не после F5.
         */
        handleReposted() {
            this.onPostReposted?.();
        },
```

### 10.3. Страницы: отдать обработчик через `provide`

IN `resources/js/Pages/Client/Feed/Index.vue` и IN `resources/js/Pages/Client/Profile/Personal.vue` — в `provide()`:
```js
    provide() {
        return {
            onPostDeleted: this.reloadPosts,
            // Обработчик тот же самый: и удаление, и репост меняют состав списка
            // и meta.total. Второй метод писать не нужно.
            onPostReposted: this.reloadPosts,
        };
    },
```

Именно здесь выполняется последний пункт задания. Репост — пост с `author_id` текущего профиля, `personal()` выбирает `$profile->posts()`, `reloadPosts()` перезапрашивает этот список — и репост встаёт первым среди обычных постов. Серверный код под это не правился вовсе.

### 10.4. Страница поста

IN `resources/js/Pages/Client/Post/Show.vue` — у страницы своя разметка, не `ItemPost`, поэтому кнопку добавляем отдельно. Прежний одиночный `LikeButton` с `class="mt-6 text-sm"` заменяется на строку из двух кнопок:
```vue
        <div class="mt-6 flex items-center gap-4">
            <LikeButton
                :url="route('client.posts.likes.toggle', post.id)"
                :initial-liked="post.is_liked"
                :initial-count="post.likes_count"
                icon-class="h-6 w-6"
                class="text-sm"
            />

            <RepostButton :post="post" :initial-count="post.reposts_count" />
        </div>
```

И подпись репоста — после заголовка `<h1>`:
```vue
        <p v-if="post.parent" class="mb-2 text-sm text-gray-500">
            Репост:
            <Link :href="route('client.posts.show', post.parent.id)" class="text-sky-700 hover:underline">
                {{ post.parent.title }}
            </Link>
        </p>
```

Обработчика `@reposted` здесь нет: перезапрашивать нечего, счётчик кнопка обновит сама.

IN `<script>` — импорт и регистрация `RepostButton`.

## 11. Тесты

IN `tests/Feature/ClientRepostTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientRepostTest extends TestCase {
    use RefreshDatabase;

    public function test_user_reposts_post(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.reposts.store', $post), [
                'title' => 'Смотрите, что нашёл',
            ])
            ->assertCreated()
            ->assertJsonPath('reposts_count', 1);

        // Проверяем всё, что подставил сервер: родителя, автора из сессии
        // и скопированный текст. parent_id тут — главная строка теста: забытый
        // 'parent_id' в $fillable даст обычный пост без родителя, и ошибка
        // не проявится ничем, кроме этой проверки.
        $this->assertDatabaseHas('posts', [
            'parent_id' => $post->id,
            'author_id' => $profile->id,
            'title' => 'Смотрите, что нашёл',
            'content' => $post->content,
            'status' => Post::STATUS_PUBLISHED,
        ]);
    }

    public function test_repost_requires_free_title(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        // Заголовок оригинала занят — именно из-за unique в схеме модалка
        // и спрашивает новый. Без правила unique тот же запрос дал бы 500.
        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.posts.reposts.store', $post), [
                'title' => $post->title,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_moderated_post_cannot_be_reposted(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_MODERATE]);

        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.posts.reposts.store', $post), [
                'title' => 'Черновик наружу',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('posts', ['title' => 'Черновик наружу']);
    }

    public function test_repost_appears_in_personal_posts(): void {
        $original = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.reposts.store', $original), [
                'title' => 'Мой репост',
            ])
            ->assertCreated();

        // Пункт задания целиком: репост лежит в «Моих публикациях» среди обычных
        // постов, и в карточке видно, с чего он сделан. Проверяем пропсы страницы,
        // а не HTML: страницу рисует Vue, сервер отдаёт данные.
        $this->actingAs($profile->user)
            ->get(route('client.profiles.personal'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('posts.data', 1)
                ->where('posts.data.0.title', 'Мой репост')
                ->where('posts.data.0.parent.title', $original->title)
                ->etc());
    }

    public function test_repost_survives_deletion_of_original(): void {
        $original = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $repost = Post::factory()->create(['parent_id' => $original->id]);

        // Автор удаляет свой пост, у которого есть чужой репост. Без nullOnDelete()
        // этот запрос упал бы 500-й от внешнего ключа, а с cascadeOnDelete()
        // удалил бы чужую публикацию.
        $this->actingAs($original->author->user)
            ->deleteJson(route('client.posts.destroy', $original))
            ->assertNoContent();

        $this->assertDatabaseHas('posts', [
            'id' => $repost->id,
            'parent_id' => null,
        ]);
    }
}
```

Два последних теста проверяют не фичу, а её границы: один — требование задания целиком, второй — поведение внешнего ключа, которое руками воспроизводится только через удаление поста с репостом.

`assertInertia()` — помощник из `inertia-laravel`: он разбирает пропсы страницы. `->etc()` говорит «остальные ключи меня не интересуют» — без него проверка требует перечислить их все.

## 12. Проверка

1. `php artisan migrate` — миграция проходит, в `posts` появилась `parent_id`.
2. `php artisan route:list --path=reposts` — один маршрут `POST posts/{post}/reposts`.
3. `php artisan test --filter=ClientRepostTest` — пять тестов зелёные.
4. `php artisan test --filter=ClientPostDestroyTest` — старые тесты удаления не сломались.
5. `vendor/bin/pint --dirty` — правок стиля нет.
6. `/feed`: у каждой карточки рядом с сердечком появилась иконка репоста со счётчиком.
7. Клик по иконке — открылось модальное окно с заголовком оригинала и пустым полем.
8. Клик по затемнённой области вне окна — окно закрылось. Escape — тоже.
9. Кнопка «Repost» неактивна, пока поле пустое.
10. Ввод заголовка и «Repost»: окно закрылось, счётчик под иконкой стал `1`.
11. Повторный репост того же поста с ТЕМ ЖЕ заголовком: окно осталось открытым, под полем красным — сообщение о занятом названии, текст в поле сохранился.
12. `/profiles/personal`: репост стоит первым, под заголовком — строка «Репост: <оригинал> · <автор>», ссылка ведёт на оригинал.
13. Репост открывается как обычный пост: у него свои лайки и свои комментарии, независимые от оригинала.
14. Удаление оригинала (кнопка «Удалить» у его автора): 204, репост остался, строка «Репост: …» из его карточки исчезла.
15. Вкладка Network при репосте: `POST /posts/5/reposts` со статусом 201 и телом `{ "reposts_count": 1 }`.

## 13. Грабли

- **`SQLSTATE... violates foreign key constraint` при удалении поста** — в миграции забыт `nullOnDelete()`, и база защищает родителя от удаления.
- **Удаление поста уносит чужие репосты** — вместо `nullOnDelete()` написан `cascadeOnDelete()`.
- **Миграция не проходит: «column parent_id contains null values»** — забыт `nullable()`; у существующих постов родителя нет и быть не может.
- **Репост создался, но `parent_id` в базе пустой** — `'parent_id'` не добавлен в `$fillable`. Массовое присвоение отбросило ключ молча, ошибки не будет.
- **500 вместо 422 при занятом заголовке** — в правилах нет `unique:posts,title`, и дубль доезжает до INSERT.
- **422 с «поле content обязательно»** — `prepareForValidation()` не подставил `content`, либо `$this->route('post')` вернул null: имя параметра в маршруте (`{post}`) должно совпадать с ключом в `route()`.
- **В карточке репоста «Без категории»** — не скопирован `category_id`.
- **Строка «Репост: …» не появляется** — в контроллере не добавлен `with('parent.author')`; в JSON просто нет ключа `parent`, и `v-if` не срабатывает. Видно во вкладке Network.
- **`Cannot read properties of null (reading 'title')`** — у `parent` не проверен null: оригинал удалён, `parent` приехал как `null`, а не отсутствует.
- **Бесконечная рекурсия или гигантский JSON** — в `PostResource` вместо `whenLoaded('parent')` стоит безусловное `PostResource::make($this->parent)`, и ресурс спускается по цепочке до конца.
- **Счётчик всегда `0`** — забыт `withCount('reposts')` в контроллере или ключ `reposts_count` в ресурсе.
- **Модалка открывается сразу у всех карточек списка** — `isModalShown` заведён в `ItemPost` или на странице, а не внутри `RepostButton`: одно состояние на весь список.
- **Страница дёргается вправо при открытии окна и влево при закрытии** — `Modal.vue` ставит `body { overflow: hidden }`, чтобы фон не прокручивался, и полоса прокрутки исчезает: область контента становится шире на её ширину (в Windows ~15px). Лечится одной строкой в `resources/css/app.css` — `html { scrollbar-gutter: stable; }` в `@layer base`: место под полосу резервируется постоянно. Чинить в `Modal.vue` (замерять ширину полосы и компенсировать `padding-right`) можно, но это JS вместо CSS и правка файла, который пришёл с Breeze и обслуживает ещё и модалку удаления аккаунта.
- **Клик внутри окна закрывает его** — модалка написана своя, и панель вложена в затемнённую подложку. Либо ставить `@click.stop` на панель, либо использовать `Modal.vue`, где подложка и панель — соседи.
- **Окно не закрывается после успеха** — в `then()` не выставлен `isModalShown = false`.
- **Введённый заголовок исчезает после ошибки** — `this.title = ''` стоит вне `then()`.
- **Репост не появляется в «Моих публикациях» без F5** — страница не отдала `onPostReposted` через `provide()`, либо `ItemPost` не объявил его в `inject`.
- **`Missing required prop: post` в консоли** — в `Show.vue` компоненту передан `:url`, а не `:post`: у `RepostButton` контракт другой, чем у `LikeButton`.

## 14. Что можно сделать лучше

**Свой текст к репосту.** Сейчас копируется содержимое оригинала. Естественное продолжение — второе поле в модалке: «что вы об этом думаете». Тогда `content` — комментарий автора репоста, а текст оригинала показывается вложенной карточкой, как в Twitter. Понадобится решить, что рисовать, когда оригинал удалён.

**Страница «Репосты публикации».** Связь `reposts()` уже есть, сделать счётчик кликабельным недолго: `GET posts/{post}/reposts` и список карточек — тот же приём, что у ветки ответов в 26-м уроке.

**Запрет репостить самого себя и репостить дважды.** Первое — правило вкуса (соцсети обычно разрешают), второе потребовало бы проверки по паре `parent_id` + `author_id`. Сейчас от дублей спасает только уникальность заголовка, а это побочный эффект, а не правило.

**Автозаголовок.** Поле можно предзаполнять («Репост: <оригинал>») с автофокусом и выделением текста — заметно быстрее, чем набирать с нуля. Уникальность всё равно проверит сервер.

**Политики.** «Можно ли показывать/трогать этот пост» — уже третье правило видимости в клиентских контроллерах (`show`, `destroy`, теперь `storeRepost`). Это явный запрос на `PostPolicy`.

**Уведомление автору оригинала.** «Вашу публикацию репостнули» — тот же повод для событий и очередей, что и ответ на комментарий.

**Снимок или ссылка.** Скопированный `content` живёт своей жизнью: оригинал отредактировали — репост остался прежним. Это защищает от подмены смысла задним числом, но расходится с ожиданием «репост показывает оригинал». Решение зависит от продукта, и принимать его стоит осознанно, а не по умолчанию.
