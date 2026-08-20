# Lesson 17 - Просмотр поста, теги и транзакция

Цель урока: показать созданный пост целиком. По пути разбираются route model binding и `show()`, страница `Show.vue` с картинками, сборка URL изображения (аксессор против ресурса), теги из текстового поля через запятую и, наконец, транзакция — чтобы пост, изображения и теги сохранялись «всё или ничего».

Отправная точка — состояние после 16-го урока: пост создаётся, файлы падают в `storage/app/public/images`, `PostService::store()` пишет их через полиморфную связь `Post::images()`.

## 1. Роут и метод `show()`

IN `routes/web.php`:
```php
Route::get('/admin/posts', [PostController::class, 'index'])->name('admin.posts.index');
Route::get('/admin/posts/create', [PostController::class, 'create'])->name('admin.posts.create');
Route::post('/admin/posts', [PostController::class, 'store'])->name('admin.posts.store');
Route::get('/admin/posts/{post}', [PostController::class, 'show'])->name('admin.posts.show');
```

**Порядок объявления важен.** Laravel проверяет маршруты сверху вниз и берёт первый подошедший. Если `/admin/posts/{post}` окажется выше `/admin/posts/create`, то при клике на «Создать» слово `create` попадёт в параметр `{post}`, Laravel пойдёт искать пост с таким id и вернёт 404. Поэтому конкретные сегменты объявляются раньше параметров.

Альтернатива, снимающая зависимость от порядка, — ограничить параметр:
```php
Route::get('/admin/posts/{post}', [PostController::class, 'show'])
    ->whereNumber('post')
    ->name('admin.posts.show');
```
`whereNumber()` добавляет регулярку `[0-9]+` к сегменту: строка `create` этому маршруту больше не подходит ни при каком порядке. Есть и родственники: `whereAlpha()`, `whereAlphaNumeric()`, `whereUuid()`, `whereIn()`.


IN `app/Http/Controllers/Admin/PostController.php`:
```php
public function show(Post $post): Response {
    // Связи грузим явно: PostResource отдаёт category/images/tags через whenLoaded(),
    // и без load() эти ключи просто не появятся в props — на клиенте будет undefined.
    $post->load(['category', 'images', 'tags']);

    return inertia('Admin/Post/Show', [
        'post' => PostResource::make($post)->resolve(),
    ]);
}
```

Что здесь изучается:

- **Route model binding.** Тайп-хинт `Post $post` вместо `int $id` — это неявная привязка модели: Laravel берёт сегмент `{post}` из URL, ищет `Post::findOrFail($id)` и передаёт готовую модель. Имя параметра в роуте (`{post}`) и имя аргумента (`$post`) должны совпадать, иначе привязка не сработает и в метод приедет строка. Несуществующий id даёт 404 автоматически — писать `if (! $post) abort(404)` не нужно.
- **`load()` против `with()`.** `with()` ставится на запрос **до** выполнения (`Post::with('images')->get()`), `load()` — на **уже полученную** модель. В `index()` мы использовали `with()`, потому что запрос строим сами; здесь модель отдал контейнер, поэтому `load()`.
- **`whenLoaded()` замыкает цепочку.** В `PostResource` ключи `category`, `images`, `tags` обёрнуты в `whenLoaded()` — если связь не загружена, ключ **молча исчезает** из ответа. Это защита от N+1, но и главная ловушка: забыл `load()` — на клиенте `post.images` будет `undefined`, а не пустым массивом.
- **Тип ответа отличается от `store()`.** `show()` возвращает `Inertia\Response` — это обычный переход по ссылке, Inertia подменит компонент страницы. `store()` возвращает массив (JSON), потому что форму отправляет axios обычным XHR.


## 2. `Show.vue`

New → Vue Component `resources/js/Pages/Admin/Post/Show.vue`

IN `resources/js/Pages/Admin/Post/Show.vue`:
```vue
<template>
    <Head :title="post.title" />

    <Link
        :href="route('admin.posts.index')"
        class="mb-4 inline-block bg-sky-700 px-3 py-2 text-xs text-white hover:bg-sky-800"
    >
        Посты
    </Link>

    <article class="rounded-lg bg-white p-6 shadow">
        <h3 class="mb-2 text-2xl font-semibold text-gray-900">{{ post.title }}</h3>

        <p class="mb-4 text-sm text-gray-500">
            {{ post.category?.title ?? 'Без категории' }} · автор #{{ post.author_id }}
        </p>

        <!--
            post.images?.length, а не post.images.length: ключ приходит из whenLoaded()
            и при незагруженной связи его в props вообще нет — обращение к .length
            у undefined уронит рендер страницы.
        -->
        <div v-if="post.images?.length" class="mb-4 grid grid-cols-3 gap-2">
            <img
                v-for="image in post.images"
                :key="image.id"
                :src="image.url"
                :alt="post.title"
                class="h-40 w-full rounded object-cover"
            />
        </div>

        <p class="whitespace-pre-line text-gray-700">{{ post.content }}</p>

        <ul v-if="post.tags?.length" class="mt-4 flex flex-wrap gap-2">
            <li
                v-for="tag in post.tags"
                :key="tag.id"
                class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600"
            >
                #{{ tag.title }}
            </li>
        </ul>
    </article>
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
    name: 'Show',
    layout: AdminLayout,
    components: { Head, Link },
    props: {
        // required: true, а не default: страница без поста не имеет смысла;
        // если props не приедет, Vue напишет об этом в консоли предупреждением.
        post: {
            type: Object,
            required: true,
        },
    },
};
</script>
```

Что здесь изучается:

- **`:src="image.url"`** — путь склеен на бэке. Клиент не знает ни про префикс `/storage/`, ни про диск: он подставляет готовую строку (см. п.4).
- **`:key` в `v-for`.** На скриншоте курса ключа нет, и Vue напишет в консоль предупреждение. `:key` — стабильный идентификатор элемента списка, по нему Vue понимает, какие DOM-узлы переиспользовать при перерисовке.
- **`:alt`** — не украшение: без него скринридер прочитает имя файла, а при битой картинке пользователь увидит пустой прямоугольник.
- **`?.` и `??`.** `post.category?.title ?? 'Без категории'` — опциональная цепочка плюс значение по умолчанию. Обе конструкции — обычный JavaScript, не магия Vue, но во Vue-шаблонах они нужны постоянно: `whenLoaded()` и `nullable`-колонки регулярно отдают `undefined`/`null`.
- **`whitespace-pre-line`** — Tailwind-класс для CSS `white-space: pre-line`: сохраняет переносы строк, набранные в `<textarea>`. Без него весь текст склеится в один абзац.

## 3. Ссылки на просмотр из `Index.vue`

IN `resources/js/Pages/Admin/Post/Index.vue`:
```vue
<td class="max-w-md px-4 py-4">
    <Link
        :href="route('admin.posts.show', post.id)"
        class="font-medium text-sky-700 hover:underline"
    >
        {{ post.title }}
    </Link>
    <p class="mt-1 text-gray-500">{{ excerpt(post.content) }}</p>
</td>
```

**`route()` с параметром.** Второй аргумент Ziggy-хелпера — значения сегментов URL. Для одного параметра достаточно скалярного значения: `route('admin.posts.show', post.id)` → `/admin/posts/7`. Развёрнутая форма — объект: `route('admin.posts.show', { post: post.id })`; она обязательна, когда параметров несколько.

Ziggy умеет и короче: если передать весь объект, он возьмёт из него поле по имени параметра — `route('admin.posts.show', post)` тоже даст `/admin/posts/7`, потому что параметр называется `post`, а у объекта есть `id`.

Забытый параметр даёт понятную ошибку в консоли: `Ziggy error: 'post' parameter is required for route 'admin.posts.show'`.

`Link`, а не `<a href>`: обычная ссылка перезагрузит страницу целиком, `Link` сделает XHR и подменит только компонент — layout с сайдбаром останется на месте.

## 4. URL изображения: аксессор или ресурс

`php artisan make:resource Image/ImageResource`

В курсе `ImageResource` создаётся именно здесь, у нас он появился в 16-м уроке — команда приведена для полноты, повторно её запускать не нужно.

IN `app/Http/Resources/Image/ImageResource.php`:
```php
public function toArray(Request $request): array {
    return [
        'id' => $this->id,
        'img_path' => $this->img_path,
        'url' => Storage::disk('public')->url($this->img_path),
    ];
}
```

В БД лежит относительный путь (`images/9x2k....jpg`) — так и должно быть: путь не зависит от домена, и при переезде проекта ничего переписывать не придётся. А фронтенду нужен URL, поэтому склейку делает `Storage::disk('public')->url()`, который берёт префикс из `config/filesystems.php`:

```php
'public' => [
    'driver' => 'local',
    'root' => storage_path('app/public'),
    'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
],
```

Отсюда важное следствие: URL строится из **`APP_URL`**, а не из адреса текущего запроса. Если в `.env` написано `http://localhost:8000`, а сайт открыт как `http://line.test`, картинки будут запрашиваться с чужого хоста и не покажутся.

**Второй способ — аксессор на модели.** Курс кладёт логику туда:

IN `app/Models/Image.php` (вариант курса):
```php
public function getUrlAttribute(): string {
    return Storage::disk('public')->url($this->img_path);
}
```

Современная форма того же самого (Laravel 9+), её и стоит использовать, если выбирать аксессор:

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

protected function url(): Attribute {
    return Attribute::get(fn (): string => Storage::disk('public')->url($this->img_path));
}
```

Аксессор — вычисляемое свойство модели: `$image->url` работает так же, как настоящая колонка, хотя в таблице её нет. Имя метода (`url`) задаёт имя свойства.

Чем варианты отличаются:

- **Аксессор доступен везде** — в Blade, в консольной команде, в любом ресурсе. Ресурс знает про URL только внутри одного API-представления.
- **Аксессор не попадает в JSON автоматически.** `$image->toArray()` его не покажет, пока не добавить `protected $appends = ['url'];` — а `$appends` заставляет считать URL при каждой сериализации, даже там, где он не нужен.
- **Ресурс не трогает модель.** Модель остаётся описанием таблицы и связей, а «как это показать клиенту» живёт в слое представления. Для проекта, где данные уходят наружу только через ресурсы, это чище.

В проекте остаётся вариант с ресурсом — трогать `Image` не нужно. Разница между слоями (модель ↔ ресурс) здесь важнее выбора.

## 5. Теги: textarea → массив

Теги вводятся простым текстом через запятую: `laravel, vue, inertia`. Значит, где-то строку надо разобрать в массив названий.

### Клиент

IN `resources/js/Pages/Admin/Post/Create.vue`:
```vue
<template>
    <textarea
        v-model="tags"
        placeholder="теги через запятую"
        class="mb-4 w-full border border-gray-200 p-4"
    ></textarea>
</template>

<script>
export default {
    data() {
        return {
            post: {
                title: '',
                content: '',
                category_id: null,
            },
            images: [],
            tags: '',
        };
    },
    methods: {
        storePost() {
            const formData = new FormData();

            Object.entries(this.post).forEach(([key, value]) => {
                formData.append(key, value ?? '');
            });

            this.images.forEach((image) => {
                formData.append('images[]', image);
            });

            formData.append('tags', this.tags);

            axios
                .post(route('admin.posts.store'), formData)
                .then((res) => {
                    console.log(res.data);

                    this.post = { title: '', content: '', category_id: null };
                    this.images = [];
                    this.tags = '';
                    this.$refs.imagesInput.value = '';
                })
                .catch((e) => {
                    console.log(e.response);
                });
        },
    },
};
</script>
```

`tags` лежит **рядом** с `post`, а не внутри него, — по той же причине, что и `images`: в объекте `post` собраны только колонки таблицы `posts`, и он целиком уходит в `Post::create()`. Теги — связь через `taggables`, колонки `tags` в таблице нет.

Отправляем сырую строку, а не разбираем её в JS. Разбор — правило приложения: клиентов может быть несколько (форма, API, консольный импорт), и каждый разберёт строку немного по-своему. Плюс данным от клиента всё равно нельзя верить, так что проверять пришлось бы на бэке повторно.

### Разбор и валидация

IN `app/Http/Requests/Admin/Post/StoreRequest.php`:
```php
public function rules(): array {
    return [
        // ...
        'tags' => ['array'],
        'tags.*' => ['string', 'max:50'],
    ];
}

protected function prepareForValidation(): void {
    $this->merge([
        'author_id' => auth()->user()->profile?->id,
        'published_at' => now(),
        'tags' => $this->tagTitles(),
    ]);
}

/**
 * "laravel, vue , laravel" → ['laravel', 'vue'].
 *
 * @return list<string>
 */
private function tagTitles(): array {
    return collect(explode(',', (string) $this->input('tags')))
        ->map(fn (string $title): string => trim($title))
        ->filter(fn (string $title): bool => $title !== '')
        ->unique()
        ->values()
        ->all();
}
```

`prepareForValidation()` уже знаком по 16-му уроку: он выполняется **до** `rules()`, поэтому в валидацию и в `validated()` уезжает уже массив. Тот же механизм, что подмешивает `author_id`, здесь приводит поле к нужному типу — это его вторая типовая задача (первая — добавить поле, второй — нормализовать пришедшее).

Разбор цепочкой коллекции по шагам:

- `explode(',', ...)` — строка в массив кусков. `(string)` нужен, потому что поля может не быть в запросе вовсе, и `input()` вернёт `null`.
- `trim()` — «` vue`» и «`vue`» должны стать одним тегом, иначе в справочнике заведутся близнецы с пробелами.
- `filter(... !== '')` — выкидывает пустые куски от `laravel,,vue` и от хвостовой запятой. Явное сравнение, а не `filter()` без аргумента: пустой `filter()` отбросил бы ещё и строку `"0"`, которая формально валидный тег.
- `unique()` — `laravel, laravel` не должен дважды прилетать в `sync()`.
- `values()` — сбрасывает ключи. `filter()` и `unique()` сохраняют исходные индексы, и после них массив становится «дырявым» (`[0 => 'laravel', 2 => 'vue']`). Для валидации по `tags.*` это неважно, а вот в JSON такой массив превратится в объект — привычка звать `values()` после фильтрации экономит время.
- `all()` — коллекция обратно в массив.

`tags.*` — правило для каждого элемента; звёздочка та же, что у `images.*` из прошлого урока.

## 6. `TagService`: справочник и `sync()`

`php artisan make:class Services/TagService`

IN `app/Services/TagService.php`:
```php
namespace App\Services;

use App\Models\Tag;
use Illuminate\Support\Collection;

class TagService {
    /**
     * Превращает список названий в модели тегов, создавая недостающие.
     *
     * @param  list<string>  $titles
     * @return Collection<int, Tag>
     */
    public static function storeBatch(array $titles): Collection {
        return collect($titles)->map(
            fn (string $title): Tag => Tag::firstOrCreate(['title' => $title]),
        );
    }
}
```

**`firstOrCreate()`** — ищет запись по переданным атрибутам и создаёт её, если не нашёл; в обоих случаях возвращает модель. Именно то, что нужно справочнику: теги общие для всех постов, второй пост с тегом `laravel` должен переиспользовать существующую строку, а не плодить копии.

Рядом стоит запомнить соседей:

- `firstOrNew()` — то же самое, но модель не сохраняется (нужен явный `save()`).
- `updateOrCreate()` — второй аргумент содержит поля, которые нужно записать и при создании, и при обновлении.
- У `firstOrCreate()` тоже есть второй аргумент: атрибуты **только для создания** — они не участвуют в поиске. Например, `Tag::firstOrCreate(['title' => $title], ['slug' => Str::slug($title)])`.

Привязка тегов к посту:

```php
$post->tags()->sync($tags->pluck('id'));
```

- **`sync()`** приводит набор связей к переданному списку: чего нет — добавит, лишнее — удалит. Для только что созданного поста хватило бы `attach()`, но `sync()` идемпотентен и без изменений переедет в будущий `update()`.
- **`pluck('id')`** вытаскивает из коллекции моделей одно поле — получается коллекция id, ровно то, что ждёт `sync()`.
- Связь `Post::tags()` объявлена как `morphToMany(Tag::class, 'taggable')->withTimestamps()`, поэтому в строки `taggables` попадут ещё и `created_at`/`updated_at`. Без `withTimestamps()` ошибки не будет — `$table->timestamps()` создаёт nullable-колонки, — но история «когда навесили тег» потеряется.
- Уникальный индекс `(tag_id, taggable_type, taggable_id)` из миграции `taggables` страхует от дублей на уровне БД, даже если `unique()` в разборе строки кто-то уберёт.

Чтобы теги доехали до клиента, их нужно добавить в ресурс.

IN `app/Http/Resources/Post/PostResource.php`:
```php
'tags' => $this->whenLoaded(
    'tags',
    fn (Collection $tags): array => TagResource::collection($tags)->resolve(),
),
```

## 7. `ImageService`: симметрия с тегами

`php artisan make:class Services/ImageService`

IN `app/Services/ImageService.php`:
```php
namespace App\Services;

use App\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageService {
    /**
     * Кладёт файлы на диск и привязывает их к посту через полиморфную связь.
     *
     * @param  list<UploadedFile>  $images
     */
    public static function storeBatch(array $images, Post $post): void {
        foreach ($images as $image) {
            $post->images()->create([
                'img_path' => Storage::disk('public')->put('/images', $image),
            ]);
        }
    }
}
```

Код переехал из `PostService` без изменений. Смысл переезда — читаемость: после рефакторинга тело `store()` состоит из четырёх строк, каждая из которых называет свой шаг, и в нём сразу видно, что именно защищает транзакция. Заодно загрузку картинок можно будет позвать из сохранения комментария или профиля.

Сигнатура `Post $post` — учебное упрощение: строго говоря, методу нужен любой объект со связью `images()`. Обобщать сейчас рано — понадобится, когда появится второй вызывающий.

## 8. Транзакция в `PostService::store()`

Сейчас сохранение состоит из трёх независимых записей в БД: пост, строки в `images`, строки в `taggables`. Если пост создался, а на тегах вылетело исключение, в базе останется полупост — без картинок и тегов, но с уникальным `title`, который заблокирует повторную отправку формы.

Транзакция делает несколько запросов одной неделимой операцией: либо применяются все, либо ни один.

Вариант курса — ручное управление:

```php
try {
    DB::beginTransaction();
    $post = Post::create($data['post']);
    ImageService::storeBatch($data['images'], $post);
    $tags = TagService::storeBatch($data['tags']);
    $post->tags()->sync($tags->pluck('id'));
    DB::commit();
} catch (\Exception $exception) {
    DB::rollBack();
}
```

Механика читается прямо: `beginTransaction()` открывает транзакцию, `commit()` фиксирует, `rollBack()` откатывает всё сделанное с момента открытия. Но у этого фрагмента три проблемы, и их стоит разобрать до того, как копировать код себе.

**Проглоченное исключение.** В `catch` нет `throw`. Значит, ошибка нигде не всплывёт: метод с типом возврата `Post` дойдёт до конца и не вернёт ничего — вместо настоящей причины сбоя получится `TypeError: return value must be of type Post, none returned`. Пользователь увидит 500 без объяснений, в логе — сообщение не о той ошибке. Правило: **ловим, чтобы откатить, и бросаем дальше**.

**`\Exception` ловит не всё.** `TypeError`, `ValueError`, `Error` наследуются от `\Error`, а не от `\Exception`; общий предок у них — интерфейс `\Throwable`. Опечатка в имени метода вылетит мимо `catch (\Exception)`, транзакция не откатится и останется открытой до конца запроса. Ловить нужно `\Throwable`.

**Забытый `commit()`.** При ручном управлении легко выйти из метода по `return` в середине — транзакция повиснет незакрытой.

Все три снимает замыкание.

IN `app/Services/PostService.php`:
```php
namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PostService {
    /**
     * Создание поста вместе с изображениями и тегами.
     *
     * Метод ничего не знает про HTTP: author_id и published_at уже лежат в $data,
     * их подмешал StoreRequest::prepareForValidation().
     *
     * @param  array<string, mixed>  $data
     */
    public static function store(array $data): Post {
        // Arr::pull забирает значение и удаляет ключ за один вызов. Удалить обязательно:
        // колонок images и tags в таблице posts нет, Post::create() с ними упадёт.
        $images = Arr::pull($data, 'images', []);
        $tagTitles = Arr::pull($data, 'tags', []);

        // Транзакция: пост, картинки и теги записываются целиком или не записываются вовсе.
        // Замыкание само делает commit при успехе и rollBack при любом Throwable,
        // а исключение пробрасывает наверх — обработчик Laravel вернёт клиенту 500 и залогирует.
        return DB::transaction(function () use ($data, $images, $tagTitles): Post {
            $post = Post::create($data);

            ImageService::storeBatch($images, $post);

            $tags = TagService::storeBatch($tagTitles);
            $post->tags()->sync($tags->pluck('id'));

            // Связи подгружаем внутри: ресурс отдаёт их через whenLoaded().
            return $post->load(['category', 'images', 'tags']);
        });
    }
}
```

Что даёт `DB::transaction()`:

- **Автоматический `commit`/`rollBack`.** Замыкание отработало без исключений — коммит; вылетело что угодно (`\Throwable`, не только `\Exception`) — откат и проброс исключения дальше.
- **Возврат значения.** `DB::transaction()` возвращает то, что вернуло замыкание, поэтому `return` работает как обычно.
- **Повтор при дедлоке.** Второй аргумент — число попыток: `DB::transaction($callback, 3)`. При взаимной блокировке транзакций PostgreSQL Laravel повторит блок вместо падения.
- **`use (...)`** — замыкание в PHP не видит переменные внешней области автоматически, их нужно перечислить явно.

**Что транзакция НЕ откатывает.** Файлы. `Storage::put()` — операция файловой системы, СУБД про неё не знает: при откате строки из `images` исчезнут, а картинки останутся лежать в `storage/app/public/images` навсегда. В 16-м уроке это отмечалось как долг; здесь закрыта только половина — консистентность БД. Полное решение — запомнить пути и подчистить их при ошибке:

```php
try {
    return DB::transaction(...);
} catch (\Throwable $exception) {
    Storage::disk('public')->delete($savedPaths);

    throw $exception;
}
```

На учебном этапе оставляем как есть, но знать про это надо: **транзакция защищает только базу**. Внешние эффекты — файлы, письма, обращения к чужим API — придётся откатывать руками. Для отложенных действий у Laravel есть готовый инструмент: `DB::afterCommit(fn () => ...)` откладывает колбэк до успешного коммита, а джобу можно сказать то же самое через `->afterCommit()` при диспатче или свойство `public $afterCommit = true;`. Так письмо не уйдёт по транзакции, которую откатили.

### Про группировку данных

На скриншоте курса сервис принимает структуру `['post' => [...], 'images' => [...], 'tags' => [...]]`. Это тоже рабочий вариант, и он честнее описывает вход: сразу видно, где колонки, а где связи. Плата — контракт формы усложняется: либо клиент шлёт вложенные ключи (`post[title]`), либо запрос собирает структуру сам.

У нас `$data` остаётся плоским, а `Arr::pull()` разделяет его на месте. Так контракт формы совпадает с правилами валидации один в один, а вся перегруппировка занимает две строки.

## 9. Грабли

- **`post.images` — `undefined` на странице показа.** Забыт `load()` в `show()`: `whenLoaded()` не отдаёт незагруженные связи и делает это молча. Смотреть вкладку **Vue** в DevTools — props компонента видны сразу.
- **404 при клике на «Создать».** Роут `/admin/posts/{post}` объявлен выше `/admin/posts/create`, и слово `create` уехало в параметр. Переставить или добавить `->whereNumber('post')`.
- **`Ziggy error: 'post' parameter is required`** — вызов `route('admin.posts.show')` без второго аргумента.
- **Картинки не показываются, хотя файлы на диске есть.** Три причины по частоте: не выполнен `php artisan storage:link`; `APP_URL` в `.env` не совпадает с адресом, по которому открыт сайт; в `:src` подставлен сырой `img_path` вместо `image.url`.
- **`TypeError: return value must be of type Post, none returned`** — `catch` без `throw`. Ошибка проглочена, наружу вылезла её маскировка. Всегда пробрасывать исключение дальше после отката.
- **Транзакция не откатилась, при следующем запросе странности.** Поймали `\Exception` там, где вылетел `\Error` (например, `TypeError` или опечатка в имени метода). Ловить `\Throwable`.
- **Теги дублируются в справочнике.** Забыт `trim()` — `«vue»` и `« vue»` для БД разные строки. Стоит помнить, что регистр `firstOrCreate()` тоже различает: `Vue` и `vue` станут двумя тегами, пока названия не нормализованы (`Str::lower()`).
- **`Post::create()` падает на неизвестной колонке** — не вычищены ключи `images`/`tags` из `$data`.
- **Пустая строка тегов создаёт тег с пустым названием** — пропущен `filter()` после `explode()`. Хвостовая запятая `laravel,` даёт ровно этот эффект.
- **Файлы копятся в `storage/app/public/images` после неудачных сохранений** — ожидаемое поведение: транзакция не управляет файловой системой.

## Homework
1. Реализовать просмотр поста
2. Реализовать сохранение поста с тегами
3. Реализовать сохранение поста с гарантией целостности данных

Учесть:
- Проверка на добавление дублей тегов (`StoreRequest.php:tagTitles()`)
- Перед тем как делать `$post->images()->create(...)`, проверить пришло ли изображение