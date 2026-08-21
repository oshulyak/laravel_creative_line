# Lesson 18 - Редактирование поста: форма, картинки и ошибки

Цель урока: научиться менять уже созданный пост. По пути разбираются пара маршрутов `edit`/`update`, заполнение формы данными с сервера, подмена HTTP-метода при отправке `FormData`, `UpdateRequest` с `Rule::unique()->ignore()`, удаление файлов вместе со строками БД и, наконец, показ ошибок валидации на клиенте.

Отправная точка — состояние после 17-го урока: пост создаётся вместе с картинками и тегами внутри транзакции, страница `Show.vue` показывает результат, `PostService::store()` уже разложен на `ImageService` и `TagService`.

## 1. Маршруты `edit` и `update`

IN `routes/web.php`:
```php
Route::get('/admin/posts/{post}', [PostController::class, 'show'])
    ->whereNumber('post')
    ->name('admin.posts.show');
// Форма редактирования — отдельная страница (GET), как и create.
Route::get('/admin/posts/{post}/edit', [PostController::class, 'edit'])
    ->whereNumber('post')
    ->name('admin.posts.edit');
// Сохранение изменений. PATCH, а не PUT: форма присылает часть колонок, а не всю запись.
Route::patch('/admin/posts/{post}', [PostController::class, 'update'])
    ->whereNumber('post')
    ->name('admin.posts.update');
```

Что здесь изучается:

- **Ресурсная конвенция Laravel.** Семь стандартных экшенов: `index`, `create`, `store`, `show`, `edit`, `update`, `destroy`. Пар «страница с формой → сохранение» две: `create` + `store` для новой записи, `edit` + `update` для существующей. Мы объявляем маршруты вручную, но имена берём конвенционные — тогда `route('admin.posts.edit')` читается однозначно, а при переходе на `Route::resource()` ничего переименовывать не придётся.
- **`Route::resource()` как альтернатива.** Одна строка `Route::resource('admin/posts', PostController::class)->names('admin.posts')` объявила бы все семь маршрутов сразу. Мы этого не делаем сознательно: пока маршруты добавляются по одному за урок, руками виднее, какой URL какому методу соответствует. Посмотреть, что именно генерирует ресурс, можно командой `php artisan route:list --path=admin`.
- **PATCH против PUT.** По семантике HTTP `PUT` заменяет ресурс целиком (не переданные поля должны обнулиться), `PATCH` меняет часть. Форма шлёт `title`, `content`, `category_id`, но не трогает `author_id`, `status` и `published_at` — это `PATCH`. На практике Laravel их не различает: разницу задают правила валидации и код сервиса. Хотите принимать оба — `Route::match(['put', 'patch'], ...)`.
- **`whereNumber('post')` на всех трёх маршрутах.** `/admin/posts/{post}/edit` из-за хвоста `/edit` с `create` не конфликтует, но ограничение всё равно полезно: `/admin/posts/abc` теперь даёт 404 сразу на роутинге, не доходя до запроса в БД.
- **Порядок объявления снова важен.** `/admin/posts/{post}` (GET) должен стоять ниже `/admin/posts/create` — это разбиралось в 17-м уроке. `PATCH /admin/posts/{post}` конфликтовать ни с чем не может: по этому URL и методу больше маршрутов нет.

## 2. Кнопка «Редактировать»

### В списке постов

IN `resources/js/Pages/Admin/Post/Index.vue`:
```vue
<thead class="bg-gray-50 text-xs uppercase tracking-wider text-gray-500">
    <tr>
        <th class="px-4 py-3 text-left font-medium">ID</th>
        <th class="px-4 py-3 text-left font-medium">Превью</th>
        <th class="px-4 py-3 text-left font-medium">Заголовок</th>
        <th class="px-4 py-3 text-left font-medium">Категория</th>
        <th class="px-4 py-3 text-left font-medium">Автор</th>
        <th class="px-4 py-3 text-left font-medium">Опубликован</th>
        <th class="px-4 py-3 text-right font-medium">Действия</th>
    </tr>
</thead>
```

```vue
<td class="whitespace-nowrap px-4 py-4 text-right">
    <Link
        :href="route('admin.posts.edit', post.id)"
        class="inline-block bg-amber-600 px-3 py-2 text-xs text-white hover:bg-amber-700"
    >
        Редактировать
    </Link>
</td>
```

Колонок стало семь, значит в строке-заглушке нужно поправить `colspan`:

```vue
<tr v-if="!posts.length">
    <td colspan="7" class="px-4 py-10 text-center text-gray-500">
        Публикаций пока нет.
    </td>
</tr>
```

`colspan` — типичная забытая мелочь: таблица не сломается заметно, но при пустом списке ячейка перестанет растягиваться на всю ширину.

### На странице просмотра

IN `resources/js/Pages/Admin/Post/Show.vue`:
```vue
<div class="mb-4 flex gap-2">
    <Link
        :href="route('admin.posts.index')"
        class="inline-block bg-sky-700 px-3 py-2 text-xs text-white hover:bg-sky-800"
    >
        Посты
    </Link>

    <Link
        :href="route('admin.posts.edit', post.id)"
        class="inline-block bg-amber-600 px-3 py-2 text-xs text-white hover:bg-amber-700"
    >
        Редактировать
    </Link>
</div>
```

Обе кнопки — `Link`, а не `<a href>`: переход на форму редактирования — обычный GET-переход, Inertia подменит только компонент страницы, layout с сайдбаром останется на месте. Второй аргумент `route()` — значение сегмента `{post}` (см. 17-й урок про Ziggy).

## 3. `PostController::edit()`

IN `app/Http/Controllers/Admin/PostController.php`:
```php
/**
 * Страница с формой редактирования поста.
 *
 * Отдаёт два пропса: сам пост (со связями, которыми управляет форма) и справочник
 * категорий для <select> — то же, что create(), плюс текущие значения.
 */
public function edit(Post $post): Response {
    $post->load(['category', 'images', 'tags']);

    return inertia('Admin/Post/Edit', [
        'post' => PostResource::make($post)->resolve(),
        'categories' => CategoryResource::collection(Category::all())->resolve(),
    ]);
}
```

Что здесь изучается:

- **`edit()` — это `show()` плюс справочники.** Метод так же получает модель через route model binding и так же грузит связи через `load()`. Разница одна: форме нужны варианты для `<select>`, поэтому рядом уезжает `categories`. Появятся другие справочники (статусы, авторы) — добавятся сюда же.
- **`load(['category', ...])` нужен и здесь.** Сама связь `category` в форме не используется — `<select>` работает с `category_id`, — но грузим: `PostResource` отдаёт её через `whenLoaded()`, и без загрузки ключа `category` на клиенте не будет. Привычка «страница показывает пост → грузим тот же набор связей» экономит время на отладке.
- **Дублирование с `create()`.** Строка `CategoryResource::collection(Category::all())` теперь встречается дважды. Выносить её в приватный метод пока рано: два одинаковых вызова — ещё не дублирование, требующее абстракции. Появится третий (или у справочника появятся условия — «только активные категории») — тогда и вынесем.

## 4. `Edit.vue`: форма, заполненная данными

New → Vue Component `resources/js/Pages/Admin/Post/Edit.vue`

IN `resources/js/Pages/Admin/Post/Edit.vue`:
```vue
<template>
    <Head :title="`Редактирование: ${post.title}`" />

    <Link
        :href="route('admin.posts.show', post.id)"
        class="mb-4 inline-block bg-sky-700 px-3 py-2 text-xs text-white hover:bg-sky-800"
    >
        К посту
    </Link>

    <div class="bg-white p-4">
        <!-- Общее сообщение об ошибке: один блок наверху формы (см. п.10). -->
        <p
            v-if="message"
            class="mb-4 border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ message }}
        </p>

        <input
            v-model="form.title"
            placeholder="title"
            class="mb-1 w-full border border-gray-200 p-4"
        />
        <p v-if="error('title')" class="mb-3 text-xs text-red-600">{{ error('title') }}</p>

        <textarea
            v-model="form.content"
            placeholder="content"
            class="mb-1 w-full border border-gray-200 p-4"
        ></textarea>
        <p v-if="error('content')" class="mb-3 text-xs text-red-600">{{ error('content') }}</p>

        <select
            v-model="form.category_id"
            class="mb-1 w-full border border-gray-200 p-4"
        >
            <option :value="null" disabled>Категория</option>
            <option
                v-for="category in categories"
                :key="category.id"
                :value="category.id"
            >
                {{ category.title }}
            </option>
        </select>
        <p v-if="error('category_id')" class="mb-3 text-xs text-red-600">
            {{ error('category_id') }}
        </p>

        <!-- Уже сохранённые картинки: крестик помечает картинку на удаление (см. п.5). -->
        <div v-if="savedImages.length" class="mb-4 grid grid-cols-4 gap-2">
            <div v-for="image in savedImages" :key="image.id" class="relative">
                <img
                    :src="image.url"
                    :alt="post.title"
                    class="h-28 w-full rounded object-cover"
                />
                <button
                    type="button"
                    class="absolute right-1 top-1 rounded-full bg-red-600 px-2 py-0.5 text-xs text-white hover:bg-red-700"
                    @click="removeSavedImage(image)"
                >
                    ×
                </button>
            </div>
        </div>

        <input
            ref="imagesInput"
            type="file"
            multiple
            accept="image/*"
            class="mb-4 w-full border border-gray-200 p-4"
            @change="handleImages"
        />

        <textarea
            v-model="tags"
            placeholder="теги через запятую"
            class="mb-4 w-full border border-gray-200 p-4"
        ></textarea>

        <a
            href="#"
            class="inline-block bg-teal-700 px-3 py-2 text-xs text-white hover:bg-teal-800"
            @click.prevent="updatePost"
        >
            Сохранить
        </a>
    </div>
</template>

<script>
import axios from 'axios';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
    name: 'Edit',
    layout: AdminLayout,
    components: { Head, Link },
    props: {
        post: {
            type: Object,
            required: true,
        },
        categories: {
            type: Array,
            default: () => [],
        },
    },
    /**
     * data() выполняется после разрешения props, поэтому this.post здесь уже доступен
     * и им можно инициализировать состояние формы.
     *
     * Значения копируем, а не правим props напрямую: props принадлежат родителю
     * (страницу отдал сервер), и Vue ругается в консоль на «Avoid mutating a prop
     * directly». Плюс при неудачном сохранении исходные данные остаются нетронутыми.
     */
    data() {
        return {
            form: {
                title: this.post.title,
                content: this.post.content,
                category_id: this.post.category_id,
            },
            // Картинки, уже лежащие в БД. [...] — поверхностная копия массива:
            // без неё filter/push меняли бы массив внутри props.
            savedImages: [...(this.post.images ?? [])],
            // id картинок, помеченных на удаление; уедут на бэк вместе с формой.
            deletedImages: [],
            // Новые файлы из <input type="file">.
            newImages: [],
            // Обратная операция к разбору строки тегов на бэке: массив моделей → строка.
            tags: (this.post.tags ?? []).map((tag) => tag.title).join(', '),
            // Ошибки валидации по полям: { title: ['...'], content: ['...'] }.
            errors: {},
            message: '',
        };
    },
    methods: {
        error(field) {
            // Laravel отдаёт массив сообщений на поле; показываем первое.
            return this.errors[field]?.[0];
        },
        removeSavedImage(image) {
            this.deletedImages.push(image.id);
            this.savedImages = this.savedImages.filter((saved) => saved.id !== image.id);
        },
        handleImages(event) {
            this.newImages = Array.from(event.target.files);
        },
        updatePost() {
            const formData = new FormData();

            // Подмена метода: PHP не разбирает тело multipart у PATCH-запросов,
            // поэтому шлём POST и сообщаем Laravel настоящий метод (см. п.6).
            formData.append('_method', 'PATCH');

            Object.entries(this.form).forEach(([key, value]) => {
                formData.append(key, value ?? '');
            });

            this.newImages.forEach((image) => {
                formData.append('images[]', image);
            });

            this.deletedImages.forEach((id) => {
                formData.append('deleted_images[]', id);
            });

            formData.append('tags', this.tags);

            // Чистим прошлые ошибки перед новой попыткой, иначе исправленное поле
            // так и останется подсвеченным.
            this.errors = {};
            this.message = '';

            axios
                .post(route('admin.posts.update', this.post.id), formData)
                .then(() => {
                    // Пост сохранён обычным XHR, и Inertia об этом ничего не знает:
                    // страницу меняем руками.
                    router.visit(route('admin.posts.show', this.post.id));
                })
                .catch((error) => {
                    this.handleError(error);
                });
        },
        handleError(error) {
            // 422 — провал валидации: FormRequest вернул { message, errors }.
            if (error.response?.status === 422) {
                this.errors = error.response.data.errors;
                this.message = 'Проверьте заполнение полей';

                return;
            }

            // Всё остальное: 419, 500, обрыв сети (тогда response вообще нет).
            this.message = 'Не удалось сохранить пост. Попробуйте ещё раз.';
        },
    },
};
</script>
```

Что здесь изучается:

- **props → data.** Форме нужно **локальное изменяемое** состояние, а props — данные родителя. Копия делается один раз в `data()`, потому что `data()` вызывается при создании компонента. Если пост придёт новый (переход на `/admin/posts/9/edit` без перезагрузки), Vue переиспользует компонент, и `data()` заново не выполнится — состояние останется от прошлого поста. Для страниц Inertia это редкий случай (переход между двумя формами редактирования), лечится атрибутом `:key="post.id"` на компоненте страницы или `watch` на пропсе. Знать про это надо, городить сейчас — рано.
- **`[...(this.post.images ?? [])]`.** Спред создаёт **новый** массив. Без него `savedImages` был бы ссылкой на массив внутри props, и `filter`/`push` меняли бы данные родителя. `?? []` страхует от `whenLoaded()`: связь не загрузили — ключа нет — `undefined` вместо массива.
- **Теги в обе стороны.** Бэк разбирает строку в массив (`tagTitles()`), фронт при открытии формы делает обратное: `map(...).join(', ')`. Это цена простого текстового поля; когда появится нормальный компонент тегов, обе конверсии уйдут.
- **`router.visit()`.** `router` — императивный аналог компонента `Link` из `@inertiajs/vue3`. Он нужен потому, что axios сохранил пост «мимо» Inertia: адресная строка и props страницы остались прежними. `router.visit(...)` делает нормальный Inertia-переход и заодно подтягивает свежие данные поста.
- **`type="button"` у крестика.** Внутри настоящего `<form>` кнопка без явного типа считается `submit` и отправляет форму. У нас тега `<form>` нет, но привычка ставить тип избавляет от неожиданной перезагрузки страницы, когда он появится.

## 5. Картинки: удаление и добавление

Три массива вместо одного — это и есть вся модель редактирования картинок:

| Поле | Что лежит | Куда уходит |
|---|---|---|
| `savedImages` | объекты картинок из БД (`id`, `img_path`, `url`) | никуда, только для показа |
| `deletedImages` | id картинок, помеченных крестиком | `deleted_images[]` |
| `newImages` | объекты `File` из input | `images[]` |

Нажатие на крестик **ничего не удаляет на сервере**: картинка исчезает из сетки, а её id уезжает в `deletedImages`. Физическое удаление произойдёт при сохранении формы — в той же транзакции, что и остальные изменения. Отсюда три следствия:

- ушёл со страницы, не нажав «Сохранить», — ничего не потерял;
- удаление и загрузка новых картинок — одна атомарная операция: не будет состояния «старые уже стёрлись, новые не загрузились»;
- пока форма не отправлена, сервер о намерении не знает — значит, `deletedImages` надо не забыть положить в `FormData`.

**Альтернатива — удалять сразу.** Отдельный маршрут `DELETE /admin/images/{image}` и axios-запрос по клику. Так делают, когда картинок много и форма живёт долго. Плата: удаление нельзя отменить, операция перестаёт быть частью транзакции поста, а на каждую картинку появляется отдельный запрос. Для учебной формы на несколько картинок отложенный вариант проще и честнее.

**Новые файлы приходят как замена, а не как добавка.** `this.newImages = Array.from(event.target.files)` перезаписывает массив: повторный выбор файлов в том же input отменяет предыдущий. Так устроен сам элемент — его `files` всегда содержит только последний выбор. Накопление (выбрал два, потом ещё два) делается через `push` плюс собственный список, но тогда придётся самому решать, что делать с дублями.

**Превью новых файлов** — необязательное, но полезное дополнение: `URL.createObjectURL(file)` даёт временный `blob:`-URL, который можно подставить в `<img :src>`. Правило: созданный URL надо освобождать через `URL.revokeObjectURL()`, иначе файл висит в памяти вкладки до перезагрузки.

## 6. Отправка `FormData` с подменой метода

Самая неочевидная строка формы:

```js
formData.append('_method', 'PATCH');

axios.post(route('admin.posts.update', this.post.id), formData);
```

Запрос уходит методом **POST**, хотя маршрут объявлен как `Route::patch`. Почему так:

- **PHP разбирает тело `multipart/form-data` только для POST.** Массивы `$_POST` и `$_FILES` наполняет интерпретатор, и делает он это исключительно для POST-запросов. Настоящий `PATCH` с файлами придёт в `php://input` сырым потоком, а `$request->all()` окажется **пустым** — при том, что во вкладке Network payload с файлами прекрасно виден. Это ограничение PHP, а не Laravel.
- **`_method` — form method spoofing.** Laravel читает поле `_method` из тела POST-запроса и подменяет метод запроса на указанный (`Illuminate\Http\Request::createFromBase()`), после чего роутинг находит `Route::patch`. Ровно этот же механизм стоит за директивой `@method('PATCH')` в Blade-формах — она разворачивается в `<input type="hidden" name="_method" value="PATCH">`.
- **Работает только из POST.** Подменять можно POST на PUT/PATCH/DELETE. Превратить GET в POST через `_method` нельзя.
- **Route model binding не страдает.** К моменту подстановки модели метод уже подменён, поэтому `$this->route('post')` в `UpdateRequest` вернёт нормальную модель `Post`.

Если файлов в форме нет, подмена не нужна: `axios.patch(url, {...})` отправит JSON, а Laravel разберёт его сам. Мы шлём файлы всегда одним и тем же способом, чтобы у формы был один путь исполнения.

**CSRF.** Запрос по-прежнему POST на тот же домен, поэтому axios подставляет `X-XSRF-TOKEN` из куки автоматически — как в 16-м уроке. Ошибка 419 означает, что кука не дошла (другой домен) или сессия истекла.

## 7. `UpdateRequest`

`php artisan make:request Admin/Post/UpdateRequest`

IN `app/Http/Requests/Admin/Post/UpdateRequest.php`:
```php
<?php

namespace App\Http\Requests\Admin\Post;

use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest {
    /**
     * Как и в StoreRequest: генератор ставит false, полноценная проверка прав
     * появится вместе с политиками.
     */
    public function authorize(): bool {
        return true;
    }

    /**
     * Правила почти повторяют StoreRequest, но с двумя отличиями: уникальность title
     * игнорирует сам редактируемый пост, и добавлен список картинок на удаление.
     *
     * author_id и published_at здесь не принимаются вовсе: автор поста не меняется,
     * дата публикации проставлена при создании. Чего нет в правилах — того не будет
     * и в validated(), а значит, и в update().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // ignore() исключает текущую запись из проверки: без него пост,
            // сохранённый без смены заголовка, считался бы дублем самого себя.
            // Передаём модель — Rule сам возьмёт из неё первичный ключ.
            'title' => ['required', 'string', 'max:255', Rule::unique('posts', 'title')->ignore($this->route('post'))],
            'content' => ['required', 'string'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'deleted_images' => ['nullable', 'array'],
            // Проверяем не просто существование картинки, а её принадлежность
            // этому посту: иначе по чужому id можно было бы стереть картинку
            // из соседней публикации (классический IDOR).
            'deleted_images.*' => [
                'integer',
                Rule::exists('images', 'id')
                    ->where('imageable_type', Post::class)
                    ->where('imageable_id', $this->route('post')->id),
            ],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }

    /**
     * Здесь prepareForValidation() решает только вторую свою задачу — нормализацию:
     * строка тегов приводится к массиву. Подмешивать author_id и published_at,
     * как это делает StoreRequest, нельзя — они относятся к моменту создания.
     */
    protected function prepareForValidation(): void {
        $this->merge([
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
}
```

Что здесь изучается:

- **`Rule::unique()->ignore()`** — то самое правило, из-за которого редактирование ломается чаще всего. `unique:posts,title` проверяет всю таблицу, включая редактируемую строку; `ignore($post)` добавляет к запросу `and id != ?`. Аргументом можно передать модель (ключ возьмётся сам) или скалярный id: `->ignore($this->route('post')->id)`. Есть и вариант с другой колонкой: `->ignore($value, 'slug')`.
- **Отдельный класс, а не флаги в `StoreRequest`.** Соблазн переиспользовать один реквест велик, но правила расходятся уже сейчас: `author_id`/`published_at` нужны только при создании, `deleted_images` — только при обновлении, `unique` ведёт себя по-разному. Общий класс быстро оброс бы условиями `if ($this->isMethod('patch'))`. То же соглашение уже действует в `Api\Post` — там `StoreRequest` и `UpdateRequest` тоже раздельные.
- **`Rule::exists()->where()`** строит `select count(*) from images where id = ? and imageable_type = ? and imageable_id = ?`. Так проверка «картинка существует» превращается в «картинка существует **и принадлежит этому посту**». Значение `imageable_type` — полное имя класса (`App\Models\Post`), потому что morph map в проекте не настроен; появится `Relation::enforceMorphMap([...])` — тут поедет короткий алиас.
- **Валидация — не единственная защита.** Ту же принадлежность мы проверим ещё раз в сервисе, выбирая картинки через связь поста (см. п.8). Правило даёт понятную 422-ю ошибку, сервис гарантирует, что даже при снятом правиле чужое не удалится. Дублирование здесь осознанное: проверка формы и защита данных — разные слои.
- **Чего нет в `rules()`, того нет в `validated()`.** Это главная причина, почему `author_id` не подменить извне: даже если клиент подсунет поле, оно не попадёт в массив, который уедет в `update()`. Массовое присвоение защищено `$fillable`, а сюда — правилами.

## 8. Сервисы: `PostService::update()` и удаление файлов

IN `app/Services/PostService.php`:
```php
/**
 * Обновление поста вместе с изображениями и тегами.
 *
 * Симметрично store(): те же три шага (колонки, картинки, теги), та же транзакция.
 * Отличие одно — картинки не только добавляются, но и удаляются.
 *
 * @param  array<string, mixed>  $data
 */
public static function update(Post $post, array $data): Post {
    $images = Arr::pull($data, 'images', []);
    $deletedImages = Arr::pull($data, 'deleted_images', []);
    $tagTitles = Arr::pull($data, 'tags', []);

    return DB::transaction(function () use ($post, $data, $images, $deletedImages, $tagTitles): Post {
        $post->update($data);

        // Сначала удаляем, потом добавляем: порядок делает операцию понятной
        // («заменить картинки») и не даёт свежезагруженному файлу попасть под удаление,
        // если в deleted_images случайно приедет id из этого же запроса.
        ImageService::deleteBatch($deletedImages, $post);
        ImageService::storeBatch($images, $post);

        // Вот теперь sync() работает по назначению: снятые в форме теги отвяжутся,
        // новые привяжутся, оставшиеся не будут тронуты. При создании он был просто
        // безопасной привычкой — здесь он несёт всю логику обновления связи.
        $tags = TagService::storeBatch($tagTitles);
        $post->tags()->sync($tags->pluck('id'));

        // Связи подгружаем в конце: PostResource отдаёт их через whenLoaded().
        // Модель приехала из route model binding без загруженных связей, поэтому
        // load() прочитает актуальное состояние — уже без удалённых картинок.
        return $post->load(['category', 'images', 'tags']);
    });
}
```

Что здесь изучается:

- **`$post->update($data)` против `Post::create($data)`.** `update()` — это `fill()` + `save()`: он проходит через `$fillable`, поднимает события модели (`updating`/`updated`, а значит, и логирование из трейта `HasLog`) и обновляет `updated_at`. Для «тихого» обновления без событий есть `updateQuietly()`, но нам события нужны.
- **`load()` против `refresh()` — тонкость, которая выстрелит позже.** `load()` грузит связь, только если она ещё **не** загружена: повторный вызов по уже загруженной связи ничего не перечитает. Здесь это безопасно — модель пришла из route model binding «пустой», и связи читаются впервые, уже после удаления картинок. Но стоит передать в сервис пост с загруженными связями (из другого сервиса, из теста) — и `load()` молча вернёт устаревшую коллекцию вместе с удалёнными картинками. Лечится `$post->refresh()` (перечитывает модель и все загруженные связи) или точечным `$post->unsetRelation('images')->load('images')`.
- **Транзакция здесь нужнее, чем в `store()`.** При сбое на тегах откатятся и удаление картинок, и изменение колонок: пользователь увидит ошибку и свой пост в исходном виде, а не наполовину обновлённый.

IN `app/Services/ImageService.php`:
```php
/**
 * Удаляет изображения поста: строки — сразу, файлы — после успешного коммита.
 *
 * @param  list<int>  $imageIds
 */
public static function deleteBatch(array $imageIds, Post $post): void {
    if ($imageIds === []) {
        return;
    }

    // Выбираем через связь поста, а не Image::whereIn(...): чужой id просто
    // не попадёт в выборку, даже если правило валидации кто-то ослабит.
    $images = $post->images()->whereIn('id', $imageIds)->get();

    $paths = $images->pluck('img_path')->all();

    // Удаляем по одной модели, а не запросом ->delete(): так срабатывают события
    // Eloquent, а вместе с ними логирование из трейта HasLog.
    $images->each->delete();

    // Файлы стираем только после коммита. Сделай мы это сразу — откат транзакции
    // вернул бы строки в БД, но не файлы с диска: в списке появились бы битые картинки.
    // Вне транзакции afterCommit() выполняет колбэк немедленно.
    DB::afterCommit(fn () => Storage::disk('public')->delete($paths));
}
```

Что здесь изучается:

- **`DB::afterCommit()`** откладывает колбэк до успешного завершения транзакции. Это ровно тот инструмент, о котором говорилось в 17-м уроке в разделе «что транзакция НЕ откатывает»: база откатывается сама, а внешние эффекты нужно либо откладывать до коммита, либо отменять руками. Тот же механизм есть у очередей — `SomeJob::dispatch()->afterCommit()`.
- **`$images->each->delete()`** — higher order message коллекции, сокращение для `$images->each(fn (Image $image) => $image->delete())`. Каждая модель удаляется отдельным запросом, зато отрабатывают наблюдатели и `HasLog`.
- **`Storage::delete()` принимает массив** — один вызов на все пути. Несуществующий файл он молча пропускает, исключения не будет.
- **Ранний выход `if ($imageIds === [])`.** Без него был бы лишний `select ... where id in ()`. Строгое сравнение с пустым массивом, а не `empty()`: читается однозначнее и не срабатывает на `"0"`.
- **Файлы остаются сиротами при удалении поста.** Мы закрыли только сценарий «удалили картинку в форме». Удаление самого поста картинки с диска не унесёт — это долг до урока про `destroy()` и события модели.

## 9. `PostController::update()`

IN `app/Http/Controllers/Admin/PostController.php`:
```php
/**
 * Сохранение отредактированного поста.
 *
 * Как и store(), возвращает JSON: форму отправляет axios, страница не перерисовывается,
 * переход на просмотр поста инициирует клиент через router.visit().
 *
 * @return array<string, mixed>
 */
public function update(UpdateRequest $request, Post $post): array {
    $post = PostService::update($post, $request->validated());

    return PostResource::make($post)->resolve();
}
```

Порядок аргументов в сигнатуре роли не играет — контейнер разрешает их по типам, а не по позиции. Конвенция Laravel: сначала запрос, потом модели из маршрута.

## 10. Сообщение об ошибке

Клиентская часть уже написана в п.4; здесь разбирается, что именно приходит.

**Что возвращает Laravel при 422.** `FormRequest` бросает `ValidationException`. Дальше решение принимает обработчик исключений: если запрос «ожидает JSON» — вернётся JSON, иначе будет редирект назад с ошибками в сессии.

```json
{
    "message": "The title field is required. (and 1 more error)",
    "errors": {
        "title": ["The title field is required."],
        "category_id": ["The selected category id is invalid."]
    }
}
```

Наш запрос JSON получает автоматически: axios по умолчанию шлёт заголовок `Accept: application/json, text/plain, */*`, а `Request::wantsJson()` смотрит на первый тип в этом списке. Дополнительных заголовков ставить не нужно — но полезно знать, откуда берётся разница: тот же URL, отправленный обычной HTML-формой, вернул бы 302.

**Структура `errors`.** Ключ — имя поля, значение — **массив** сообщений (правил на поле может провалиться несколько), поэтому в шаблоне берётся `[0]`. Для вложенных полей ключ содержит индекс: `images.0`, `deleted_images.1`. Обращаться к таким ключам из шаблона неудобно (`this.errors['images.0']`), поэтому для файлов проще показывать общее сообщение.

**Три уровня сообщений в форме:**

- под полем — `error('title')`, конкретная причина отказа;
- сверху формы — `message`, чтобы ошибку заметили, если поле уехало за пределы экрана;
- всё, что не 422, — одно человеческое сообщение. Показывать пользователю `error.response.data.message` от 500-й нельзя: при `APP_DEBUG=true` туда попадает текст исключения с внутренностями приложения.

**Почему `error.response?.status`, а не `error.response.status`.** Если запрос вообще не дошёл до сервера (обрыв сети, CORS, отменённый запрос), у ошибки axios **нет** поля `response` — только `request` и `message`. Без опциональной цепочки обработчик ошибки сам упадёт с `TypeError`, и пользователь не увидит ничего.

**Чем это отличается от «инертийного» способа.** У Inertia есть `useForm()` (или `this.$inertia.form`), который сам отправляет данные, сам складывает ошибки в `form.errors` и умеет отправлять файлы. Мы им не пользуемся сознательно: курс сначала показывает механику на голом axios — что уходит в запросе, что приходит в ответе, где живут ошибки. Перейти на `useForm()` после этого просто, а обратно — уже не нужно.

**Что стоит добавить дальше** (за пределами урока, но по-хорошему нужно): флаг `saving`, блокирующий кнопку на время запроса. Иначе двойной клик по «Сохранить» отправит форму дважды, и второй запрос упадёт на `unique:title`.

## 11. Грабли

- **`$request->all()` пустой при сохранении, хотя в Network виден payload с файлами** — забыт `_method`, и запрос ушёл настоящим PATCH. PHP не разбирает `multipart/form-data` ни для чего, кроме POST.
- **`405 Method Not Allowed`** — обратная ситуация: `_method` есть, а маршрут объявлен как `Route::put`. Метод в `FormData` и метод в `routes/web.php` должны совпадать.
- **«The title has already been taken» при сохранении без смены заголовка** — забыт `->ignore($this->route('post'))` в правиле `unique`.
- **Форма открывается пустой** — `data()` инициализирован не из `this.post`. Смотреть вкладку **Vue** в DevTools: props компонента видны сразу. Лишний уровень `data` в пропсе означает, что где-то потерян `->resolve()` у вложенного ресурса.
- **`Avoid mutating a prop directly`** в консоли — форма правит `post.images` или `post.title` напрямую вместо копии в `data()`.
- **Удалённые картинки возвращаются в ответе на сохранение** — связь `images` была загружена **до** удаления, а `load()` уже загруженную связь не перечитывает. Нужен `refresh()` или `unsetRelation('images')`.
- **Картинка исчезла из БД, но осталась на диске (или наоборот)** — файл удалён внутри транзакции, которая потом откатилась. Удаление файлов должно быть отложено через `DB::afterCommit()`.
- **Теги пропали после сохранения** — поле `tags` не попало в `FormData`, `tagTitles()` вернул пустой массив, и `sync([])` отвязал всё. `sync()` — это «привести к списку», а не «добавить».
- **Все теги задвоились** — вместо `sync()` вызван `attach()`: он добавляет связи, не убирая существующие.
- **`Attempt to read property "id" on null` в `UpdateRequest::rules()`** — `$this->route('post')` вернул `null`. Имя параметра маршрута (`{post}`) не совпадает с тем, что запрашивают у `route()`, либо правило скопировано в реквест, который используется на маршруте без этого параметра.
- **419 Page Expired** — сессия истекла, пока форма была открыта. Проверять `SESSION_LIFETIME` и наличие куки `XSRF-TOKEN`.
- **Ошибки валидации не показываются, а в консоли лежит 302** — запрос ушёл без `Accept: application/json`. Проверять, что форма отправляется через axios, а не обычным сабмитом.
- **Строка «Публикаций пока нет» съехала** — не поправлен `colspan` после добавления колонки «Действия».
