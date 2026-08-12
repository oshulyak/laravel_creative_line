# Lesson 16 - Категория, автор, сервис и загрузка изображений

Цель урока: довести форму создания поста до состояния, в котором она заполняет все обязательные поля таблицы `posts`. По пути разбираются четыре темы: справочник в `<select>`, автор из авторизованного пользователя, закрытие админки middleware `auth` и вынос логики сохранения в сервисный слой. В конце — загрузка изображений (мультизагрузка через `FormData`).

## 1. Select категорий

Категория — справочник в БД, поэтому список вариантов не хардкодится в шаблоне, а приезжает с бэкенда пропсом. Метод `create()` перестаёт быть однострочным: страница с формой готовит для формы данные.

IN `app/Http/Controllers/Admin/PostController.php`:
```php
public function create(): Response {
    $categories = CategoryResource::collection(Category::all())->resolve();
    return inertia('Admin/Post/Create', compact('categories'));
}
```

Почему через ресурс, а не `Category::all()` напрямую: `CategoryResource` отдаёт только `id` и `title` — ровно то, что нужно для `<option>`, без служебных полей и `timestamps`. `->resolve()` превращает ресурс в обычный массив, иначе Inertia получила бы объект ресурса и обёртку `data`.

IN `resources/js/Pages/Admin/Post/Create.vue`:
```vue
<template>
    <select v-model="post.category_id" class="mb-4 w-full border border-gray-200 p-4">
        <option :value="null" disabled>Категория</option>
        <option v-for="category in categories" :key="category.id" :value="category.id">
            {{ category.title }}
        </option>
    </select>
</template>

<script>
export default {
    props: {
        categories: {
            type: Array,
            default: () => [],
        },
    },
    data() {
        return {
            post: {
                title: '',
                content: '',
                published_at: '',
                category_id: null,
            },
        };
    },
};
</script>
```

Что здесь изучается:

- **`v-model` на `<select>`** работает так же, как на `<input>`: в `post.category_id` попадает `value` выбранного `<option>`.
- **`:value` с двоеточием, а не `value`.** Без двоеточия в модель уедет строка — `"null"` вместо `null`, `"3"` вместо `3`. На скриншоте курса в первой строке написано `value="null"`, и это как раз тот случай, когда правило `integer` на бэке начинает ругаться на пустой выбор.
- **`disabled` на первом `<option>`** делает его подписью-заглушкой: он виден, пока ничего не выбрано, но выбрать его нельзя.
- **`:key` в `v-for`** — стабильный идентификатор строки. Vue по нему понимает, какие узлы переиспользовать при перерисовке списка; без него в консоли будет предупреждение.

Правило валидации берётся из схемы: `posts.category_id` — внешний ключ на `categories`.

IN `app/Http/Requests/Admin/Post/StoreRequest.php`:
```php
'category_id' => ['required', 'integer', 'exists:categories,id'],
```

`exists:categories,id` — проверка существования записи запросом в БД. Она нужна даже при наличии внешнего ключа: без неё несуществующий `id` дойдёт до `INSERT` и превратится в 500-ю ошибку от PostgreSQL вместо аккуратной 422 с сообщением для пользователя.

## 2. Автор поста: шаг 1 — в контроллере

`posts.author_id` — NOT NULL, в 15-м уроке туда временно записывалась единица. Теперь автор берётся из того, кто заполняет форму.

IN `app/Http/Controllers/Admin/PostController.php`:
```php
public function store(StoreRequest $request): array {
    $data = $request->validated();
    $data['author_id'] = auth()->user()->profile->id;

    $post = Post::create($data);

    return PostResource::make($post)->resolve();
}
```

Ключевой момент — **два разных идентификатора**. `auth()->user()` возвращает `User`, но пост принадлежит не пользователю, а его профилю: цепочка `users → profiles → posts`. Поэтому `->profile->id`, а не `auth()->id()`. `profile` — это связь `hasOne` из модели `User`; обращение к ней как к свойству выполняет ленивый запрос и отдаёт модель `Profile`.

Почему `author_id` дописывается **после** `validated()`, а не приходит из формы: значение не должно зависеть от клиента. Если поле окажется в форме, любой авторизованный пользователь сможет подменить автора поста в DevTools. `validated()` возвращает только то, что описано в `rules()`, — данные из формы, которым нельзя доверять, физически не могут туда просочиться.

Этот вариант рабочий, но логика сохранения поселилась в контроллере. Дальше по уроку она переезжает дважды.

## 3. Авторизация: закрываем админку

Строка `auth()->user()->profile->id` из предыдущего пункта падает с `Attempt to read property "id" on null`, если пользователь не авторизован. Значит, админка обязана требовать авторизации — не «для красоты», а потому что код на неё опирается.

IN `routes/web.php`:
```php
Route::group(['middleware' => 'auth'], function () {
    Route::get('/admin/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard.index');
    Route::get('/admin/posts', [PostController::class, 'index'])->name('admin.posts.index');
    Route::get('/admin/posts/create', [PostController::class, 'create'])->name('admin.posts.create');
    Route::post('/admin/posts', [PostController::class, 'store'])->name('admin.posts.store');
});
```

`Route::group(['middleware' => 'auth'], ...)` — группа маршрутов с общим middleware. Альтернативная запись `Route::middleware('auth')->group(...)` делает ровно то же самое; в проекте уже используется вторая форма для маршрутов профиля.

`auth` — псевдоним middleware `Authenticate`, который проверяет текущий guard и, если гостя не пустили, редиректит на `route('login')`. Страница логина уже есть — её поставил Breeze в 14-м уроке вместе с `routes/auth.php`.

**Про guard и грабли курса.** В курсе на этом месте ломается вход: раньше в `config/auth.php` дефолтным guard'ом был выставлен `api` (JWT), и Breeze пытался авторизовать через него. Лечение на скриншоте — переключать guard локально в API-контроллере:

```php
public function __construct() {
    Config::set(['auth.defaults.guard' => 'api']);
}
```

В нашем проекте этого делать не нужно: `AUTH_GUARD=web` в `.env`, а `Api\AuthController` и так берёт нужный guard явно — `Auth::guard('api')` в методе `guard()`. Явный guard в конкретном месте лучше, чем глобальная подмена конфига: `Config::set()` в конструкторе меняет настройку на весь запрос, и понять источник поведения из кода становится сложно.

## 4. Сервисный слой: шаг 2 — `PostService`

`php artisan make:class Services/PostService`

Зачем: контроллер отвечает за HTTP — принять запрос, отдать ответ. Как именно создаётся пост (проставить автора, положить файлы, привязать теги) — это уже логика приложения, и она нужна не только админке: тот же сценарий позже понадобится API-контроллеру, консольной команде, импорту. Пока логика в контроллере, переиспользовать её можно только копипастой.

IN `app/Services/PostService.php`:
```php
namespace App\Services;

use App\Models\Post;

class PostService {
    public static function store(array $data): Post {
        $data['author_id'] = auth()->user()->profile->id;

        return Post::create($data);
    }
}
```

IN `app/Http/Controllers/Admin/PostController.php`:
```php
public function store(StoreRequest $request): array {
    $data = $request->validated();

    $post = PostService::store($data);

    return PostResource::make($post)->resolve();
}
```

Контроллер снова стал тонким: валидированные данные → сервис → ресурс.

**Про `static`.** Курс делает метод статическим — это самый короткий путь и его достаточно для учебной задачи. Компромисс проговорить стоит: статический вызов `PostService::store()` жёстко зашит в контроллер, его нельзя подменить моком в тесте и нельзя внедрить через конструктор. Laravel-way на будущее — обычный метод и внедрение зависимости:

```php
public function store(StoreRequest $request, PostService $service): array {
    $post = $service->store($request->validated());
    // ...
}
```

Контейнер сам создаст `PostService` и передаст в метод. Переход на этот вариант — правка двух строк, поэтому начать со `static` не страшно.

## 5. `author_id`: шаг 3 — `prepareForValidation()`

У сервиса осталась проблема: `auth()` внутри него означает, что сервис работает только в контексте HTTP-запроса от залогиненного пользователя. Из консольной команды или из очереди `auth()->user()` вернёт `null`.

Правильное место для «дополнить входные данные перед проверкой» — сам FormRequest.

IN `app/Http/Requests/Admin/Post/StoreRequest.php`:
```php
public function rules(): array {
    return [
        'title' => ['required', 'string', 'max:255', 'unique:posts,title'],
        'content' => ['required', 'string'],
        'published_at' => ['nullable', 'date'],
        'category_id' => ['required', 'integer', 'exists:categories,id'],
        'author_id' => ['required', 'integer', 'exists:profiles,id'],
    ];
}

/**
 * Подмешиваем автора до запуска валидации: значение приходит не из формы,
 * а из текущего пользователя, но проверяется теми же правилами.
 */
protected function prepareForValidation(): void {
    $this->merge([
        'author_id' => auth()->user()->profile->id,
    ]);
}
```

Порядок работы FormRequest: `authorize()` → **`prepareForValidation()`** → `rules()` → `validated()`. То есть добавленный в `merge()` ключ существует уже к моменту проверки правил и поэтому попадает в результат `validated()`.

Что это даёт:

- **Сервис становится чистым.** Строка с `auth()` из `PostService::store()` удаляется — метод теперь просто `return Post::create($data);` и работает в любом контексте.
- **Автор валидируется наравне с остальным.** Правило `exists:profiles,id` поймает ситуацию «у пользователя нет профиля» до `INSERT`.
- **Контроллер не знает про `author_id` вообще.** Он передаёт в сервис `validated()` как есть.

`$this->merge()` возвращает сам объект запроса, поэтому `return` перед ним (как на скриншоте) ни на что не влияет — метод объявляется `void`.

Итоговое состояние после трёх шагов: `author_id` подмешивает `StoreRequest`, `PostService` только создаёт модель, `PostController` связывает их и отдаёт ресурс.

## 6. Мультизагрузка изображений

На скриншоте курса загружается один файл прямо в контроллере:

```php
$data['img_path'] = Storage::disk('public')->put('/images', $request->file('image'));
unset($data['image']);
```

Логика верная и её стоит разобрать построчно, но у нас есть готовая полиморфная связь `Post::images()` (таблица `images` с `img_path` + `imageable_type`/`imageable_id`), поэтому файлы кладём туда: пост может иметь много изображений, и отдельная колонка для этого не нужна.

### Клиент: `<input type="file">` и `FormData`

IN `resources/js/Pages/Admin/Post/Create.vue`:
```vue
<template>
    <input
        ref="imagesInput"
        type="file"
        multiple
        accept="image/*"
        class="mb-4 w-full border border-gray-200 p-4"
        @change="handleImages"
    />
</template>

<script>
export default {
    data() {
        return {
            post: {
                title: '',
                content: '',
                published_at: '',
                category_id: null,
            },
            images: [],
        };
    },
    methods: {
        handleImages(event) {
            // event.target.files — FileList, а не массив: Array.from даёт настоящий массив.
            this.images = Array.from(event.target.files);
        },
        storePost() {
            const formData = new FormData();

            Object.entries(this.post).forEach(([key, value]) => {
                formData.append(key, value ?? '');
            });

            this.images.forEach((image) => {
                formData.append('images[]', image);
            });

            axios
                .post(route('admin.posts.store'), formData)
                .then((res) => {
                    console.log(res.data);

                    this.post = { title: '', content: '', published_at: '', category_id: null };
                    this.images = [];
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

Три вещи, которые обязательно ломаются, если их не знать:

- **`v-model` на `<input type="file">` не работает.** Двусторонняя привязка потребовала бы записать значение обратно в поле, а браузер это запрещает из соображений безопасности — программно подставить файл в input нельзя. Поэтому файл берут в обработчике `@change` из `event.target.files`.
- **Файл нельзя отправить обычным объектом.** `axios.post(url, this.post)` сериализует данные в JSON, а `File` в JSON не превращается — на бэк приедет `{}`. Нужен `FormData`: браузер закодирует его как `multipart/form-data`, тот же формат, что у обычной HTML-формы с `enctype`.
- **Заголовок `Content-Type` вручную не ставим.** Увидев `FormData`, axios сам подставит `multipart/form-data; boundary=...`. Если написать заголовок руками, `boundary` потеряется и PHP разберёт тело как пустое — `$request->all()` будет пустым при непустом `Payload`.

`multiple` на input разрешает выбрать несколько файлов; `images[]` в имени ключа — соглашение PHP: повторяющиеся ключи с квадратными скобками собираются в массив.

`this.$refs.imagesInput.value = ''` очищает поле выбора файлов после успешного сохранения: `v-model` там нет, само оно не сбросится. `ref="imagesInput"` в шаблоне — способ Vue получить прямой доступ к DOM-элементу, когда реактивности недостаточно.

### Правила валидации

IN `app/Http/Requests/Admin/Post/StoreRequest.php`:
```php
'images' => ['nullable', 'array'],
'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
```

- `images.*` — правило для **каждого элемента** массива; звёздочка это wildcard, работающий на любой вложенности (`images.*.title` тоже валиден).
- `image` проверяет, что загруженный файл действительно картинка, а `mimes` ограничивает список форматов — на расширение в имени файла полагаться нельзя.
- `max:2048` для файлов считается **в килобайтах**, то есть 2 МБ. Ограничение сработает только в пределах лимитов PHP: `upload_max_filesize` и `post_max_size` в `php.ini` выше по стеку, и при их превышении запрос придёт в Laravel уже пустым.

### Сервер: сохранение файлов

IN `app/Services/PostService.php`:
```php
namespace App\Services;

use App\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PostService {
    /**
     * @param  array<string, mixed>  $data
     */
    public static function store(array $data): Post {
        /** @var list<UploadedFile> $images */
        $images = $data['images'] ?? [];
        unset($data['images']);

        $post = Post::create($data);

        foreach ($images as $image) {
            $post->images()->create([
                'img_path' => Storage::disk('public')->put('/images', $image),
            ]);
        }

        return $post;
    }
}
```

Разбор:

- **`unset($data['images'])` обязателен.** В `$data` лежат объекты `UploadedFile`, а в таблице `posts` колонки `images` нет — `Post::create()` с этим ключом упадёт. Это та же строка, что на скриншоте курса (`unset($data['image'])`), просто во множественном числе.
- **`Storage::disk('public')->put('/images', $image)`** сохраняет файл в `storage/app/public/images` под сгенерированным уникальным именем и **возвращает относительный путь** вида `images/9x2k....jpg`. Уникальное имя важно: два пользователя, загрузившие `photo.jpg`, не затрут файлы друг друга.
- **`$post->images()->create([...])`** создаёт запись через полиморфную связь: `imageable_type` и `imageable_id` Eloquent проставит сам, в `$fillable` модели `Image` они не нужны и не должны там быть.
- Диск `public` описан в `config/filesystems.php`. Чтобы файлы стали доступны по HTTP, нужен симлинк: **`php artisan storage:link`** — он создаёт `public/storage → storage/app/public`. Без него запись в БД появится, а картинка отдаст 404.

Полный URL для фронтенда собирается из пути: `Storage::disk('public')->url($image->img_path)` даёт `/storage/images/9x2k....jpg`. Логичное место для этого — `ImageResource`, чтобы клиент не занимался склейкой строк.

> Загрузка файлов и `INSERT` идут вне транзакции: если создание записи упадёт, файлы останутся на диске. На учебном этапе это допустимо; правильное решение — `DB::transaction()` вокруг сохранения и удаление файлов в `catch`.

## 7. Грабли

- **`Attempt to read property "id" on null`** в `auth()->user()->profile->id` — у пользователя нет профиля. Проверить сидеры: `User` и `Profile` создаются парой, профиль не должен быть опциональным для того, кто пишет посты.
- **Пустой `<select>`** — забыли передать `categories` из `create()` или в таблице `categories` нет строк. Смотреть вкладку **Vue** в DevTools: props компонента видно сразу.
- **`The category id field must be an integer`** при выбранной категории — в `<option>` написано `value` без двоеточия, и в модель уехала строка.
- **419 Page Expired при отправке `FormData`** — CSRF-токен. Обычный объект axios отправляет с заголовком из куки автоматически; при работе с `FormData` заголовок тоже подставляется, но если запрос уходит на другой домен или куки нет — токен придётся добавить явно.
- **`$request->all()` пустой, а в Network виден Payload с файлами** — вручную выставленный `Content-Type: multipart/form-data` без `boundary`. Убрать заголовок и дать axios проставить его самому.
- **Картинка сохранилась, но не открывается** — не выполнен `php artisan storage:link`, либо в `<img :src>` подставляется сырой `img_path` без префикса `/storage/`.
- **Пустые поля формы приезжают строками.** `FormData` умеет передавать только строки и файлы, поэтому `null` превратится в `"null"`, если не подставить `''`. Пустую строку middleware `ConvertEmptyStringsToNull` (входит в группу `web`) вернёт обратно в `null`, и правило `nullable` отработает как ожидалось.
- **403 на отправке формы** — по-прежнему `authorize()` в FormRequest. Проверять первым делом.
