# Lesson 15 - Creating post with Vue

Цель урока: собрать админку как SPA — общий layout с сайдбаром, SPA-ссылки между страницами, форма создания поста и отправка её данных на бэкенд через axios.

## 1. Layout: неизменная часть страницы

Проблема: если сайдбар и шапку писать прямо в каждой странице, то при добавлении пункта меню придётся править все файлы.
Решение во Vue — **layout**: повторяющаяся часть живёт в одном компоненте, а изменяемая подставляется в него через `<slot />`.

New → File `resources/js/Layouts/AdminLayout.vue` (Options API)
```vue
<template>
    <div>
        <div class="w-full bg-white p-4">
            <header>ADMIN PANEL</header>
        </div>

        <div class="flex">
            <nav class="min-h-screen w-1/4 bg-sky-700">
                <!-- ссылки добавим в п.3 -->
            </nav>

            <article class="w-3/4 p-4">
                <slot />
            </article>
        </div>
    </div>
</template>
```

`<slot />` — «дырка», в которую подставляется содержимое страницы. `<nav>`/`<article>`/`<header>` — обычные семантические теги, вместо них мог бы быть `div`.

Страница подключает layout свойством `layout` (это свойство Inertia, а не самого Vue):

IN `resources/js/Pages/Admin/Post/Index.vue`:
```vue
<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
    name: 'Index',
    layout: AdminLayout,
}
</script>
```

При SPA-переходе layout не пересоздаётся — меняется только содержимое `<slot />`.

## 2. Вторая страница — Dashboard

Dashboard — сводная страница админки со статистикой. Это не сущность, а агрегация значений из разных сущностей, поэтому модели и ресурса у неё нет. Сейчас она нужна, чтобы было между чем переключаться и проверить работу layout.

`php artisan make:controller Admin/DashboardController`
New → Vue Component `resources/js/Pages/Admin/Dashboard/Index.vue`

IN `routes/web.php`:
```php
Route::get('/admin/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard.index');
```

IN `app/Http/Controllers/Admin/DashboardController.php`:
```php
public function index(): Response {
    return inertia('Admin/Dashboard/Index');
}
```

## 3. Ссылки: `Link` вместо `<a href>`

Обычный `<a href>` вызывает **полную перезагрузку** страницы — SPA перестаёт быть SPA. Inertia даёт компонент `Link`: он перехватывает клик, делает XHR-запрос и подменяет только компонент страницы.

IN `resources/js/Layouts/AdminLayout.vue`:
```vue
<template>
    <nav class="min-h-screen w-1/4 bg-sky-700">
        <Link :href="route('admin.dashboard.index')"
              class="block border-b border-sky-900 p-4 text-xs text-gray-200">
            Dashboard
        </Link>
        <Link :href="route('admin.posts.index')"
              class="block border-b border-sky-900 p-4 text-xs text-gray-200">
            Posts
        </Link>
    </nav>
</template>

<script>
import { Link } from '@inertiajs/vue3';

export default {
    name: 'AdminLayout',
    components: {Link},
}
</script>
```

Три вещи, которые здесь изучаются:

- **Именованный импорт в фигурных скобках** — из библиотеки берём только `Link`, а не всё её содержимое.
- **`components: { Link }`** — зарезервированный ключ Options API: компонент нужно зарегистрировать, чтобы использовать как тег.
- **Двоеточие перед атрибутом** (`:href` — сокращение `v-bind:href`) — значение трактуется как JS-выражение, а не как строка. Без двоеточия в `href` попала бы буквальная строка `route('admin.posts.index')`.

`route()` — helper библиотеки **Ziggy** (подключается как `ZiggyVue` в `resources/js/app.js`). Он даёт во Vue те же именованные роуты Laravel, что и `route()` в Blade — URL не хардкодим. Если имени нет в списке роутов, в консоли появится `Ziggy error: route ... is not in the route list`.

## 4. Страница создания поста

Кнопка-ссылка на индексе:

IN `resources/js/Pages/Admin/Post/Index.vue`:
```vue
<Link :href="route('admin.posts.create')"
      class="mb-4 inline-block border border-sky-800 bg-sky-700 px-3 py-2 text-xs text-white">
    Создать
</Link>
```

Имена экшенов и роутов — по ресурсной конвенции Laravel: страница с формой это `create`, сохранение — `store`.

IN `routes/web.php`:
```php
Route::get('/admin/posts/create', [PostController::class, 'create'])->name('admin.posts.create');
Route::post('/admin/posts', [PostController::class, 'store'])->name('admin.posts.store');
```

IN `app/Http/Controllers/Admin/PostController.php`:
```php
public function create(): Response {
    return inertia('Admin/Post/Create');
}
```

## 5. Форма и `v-model`

«Древний» JS-подход — вытащить значения из DOM: `document.getElementById('title').value`. Во Vue есть `v-model` — **двусторонняя привязка**: значение поля и свойство из `data()` синхронизированы автоматически в обе стороны.

Так как работа ведётся посущностно, вместо отдельных переменных `title`, `content` заводим один объект `post` — его же целиком отправим на бэк.

IN `resources/js/Pages/Admin/Post/Create.vue`:
```vue
<template>
    <div>
        <Link :href="route('admin.posts.index')"
              class="mb-4 inline-block bg-sky-700 px-3 py-2 text-xs text-white">
            Посты
        </Link>

        <div class="bg-white p-4">
            <input v-model="post.title"
                   placeholder="title"
                   class="mb-4 w-full border border-gray-200 p-4" />

            <input v-model="post.published_at"
                   type="datetime-local"
                   class="mb-4 w-full border border-gray-200 p-4" />

            <textarea v-model="post.content"
                      placeholder="content"
                      class="mb-4 w-full border border-gray-200 p-4"></textarea>

            <a href="#"
               @click.prevent="storePost"
               class="inline-block bg-teal-700 px-3 py-2 text-xs text-white">
                Создать
            </a>
        </div>
    </div>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
    name: 'Create',
    layout: AdminLayout,
    components: {
        Link
    },
    data() {
        return {
            post: {
                title: '',
                content: '',
                published_at: ''
            }
        }
    },
}
</script>
```

`data()` — функция, возвращающая объект состояния компонента. Всё в нём реактивно: набранный в поле текст сразу оказывается в `post.title`, это видно во вкладке **Vue** в DevTools.

Набор полей берём из атрибутов модели: `title`, `content`, `published_at`. Категория, изображение и теги — отдельные темы следующих уроков.

**Все поля в `data()` перечисляем явно**, а не оставляем `post: {}`:

- Пустой объект «доедет» до нужной формы только после того, как пользователь тронет каждое поле. Незаполненное поле в объект вообще не попадёт, и на бэк уйдёт запрос без этого ключа — а там его ждёт валидация.
- `data()` работает как описание контракта формы: видно, какие поля есть у страницы, без чтения шаблона.
- Явная структура нужна для сброса формы после отправки (п.7) — сбрасывать надо в тот же набор ключей.

## 6. События: `@click.prevent` и `methods`

JavaScript в браузере слушает зарезервированные события: `click`, `change`, `focus`, `blur`, `scroll`, `resize` и т.д. Во Vue обработчик вешается через `v-on:` или сокращение `@`.

```vue
<a href="#" @click.prevent="storePost">Создать</a>
```

```vue
<script>
export default {
    methods: {
        storePost() {
            console.log(this.post);
        }
    }
}
</script>
```

- `.prevent` — модификатор, отменяющий поведение тега по умолчанию. У ссылки это подстановка `href` в адресную строку и прыжок страницы наверх; без `.prevent` при каждом клике страницу будет дёргать.
- `methods` — зарезервированный ключ Options API; слово `function` внутри опускается.
- Имя метода — **действие + сущность** (`storePost`, а не `store`): на одной странице может быть несколько сущностей (`storePost`, `storeTag`), плюс часть коротких имён (`delete`) зарезервирована в JS.
- `this` — обращение к экземпляру компонента. Из `methods` до `data` и `props` иначе не достучаться: `this.post`, `this.posts`.

## 7. Отправка данных: axios

**axios** — HTTP-клиент для браузера, по смыслу тот же `Http::post()` в Laravel: метод запроса, URL, данные.

IN `resources/js/Pages/Admin/Post/Create.vue`:
```vue
<script>
import axios from 'axios';

export default {
    methods: {
        storePost() {
            axios.post(route('admin.posts.store'), this.post)
                .then(res => {
                    console.log(res.data);      // 2xx: успешный ответ

                    this.post = {               // очищаем форму
                        title: '',
                        content: '',
                        published_at: ''
                    }
                })
                .catch(e => {
                    console.log(e.response);    // 4xx / 5xx: тело ошибки в e.response
                })
                .finally(() => {
                    // выполнится в любом случае
                });
        }
    }
}
</script>
```

Очистка идёт **в `.then()`, а не сразу после `axios.post()`**: запрос асинхронный, и поля надо сбрасывать только тогда, когда сервер подтвердил сохранение. При ошибке (`.catch()`) введённые данные должны остаться на месте, чтобы пользователь мог их исправить, а не набирать заново.

Присваиваем новый объект с тем же набором ключей — благодаря `v-model` поля в форме очистятся сами.

- `.then()` — любой успешный ответ (200, 201, 204 …), объект `response`.
- `.catch()` — ошибки 4xx/5xx, объект `error`; тело ответа лежит в `e.response`.
- `.finally()` — выполняется всегда (удобно для снятия «загрузки»).

Приложение — монолит, поэтому и здесь работает `route()` от Ziggy: путь не хардкодим.

В проекте нет `resources/js/bootstrap.js`, который делает `window.axios` глобальным, поэтому axios импортируем в компоненте явно; при отсутствии пакета — `npm install axios`.

## 8. Бэкенд: Request + `store()`

`php artisan make:request Admin/Post/StoreRequest`

Отдельное пространство имён `Admin`, потому что `Api/Post/StoreRequest` уже существует: у админки свой набор правил.

IN `app/Http/Requests/Admin/Post/StoreRequest.php`:
```php
class StoreRequest extends FormRequest {
    public function authorize(): bool {
        return true;
    }

    public function rules(): array {
        return [
            'title' => ['required', 'string', 'unique:posts,title'],
            'content' => ['nullable', 'string'],
            'published_at' => ['nullable', 'date'],
        ];
    }
}
```

`authorize()` по умолчанию возвращает `false` — это топ-1 причина внезапного 403 при отправке формы. Проверять в первую очередь.

`required` vs `nullable` берётся не из головы, а из **схемы БД — точки истины**. Смотрим миграцию `posts`: `title` — NOT NULL и unique, значит `required` + `unique`; `content` — NOT NULL, но на этом этапе форма может слать пустое; `published_at` — nullable.

IN `app/Http/Controllers/Admin/PostController.php`:
```php
public function store(StoreRequest $request): array {
    $data = $request->validated();

    $post = Post::create($data);

    return PostResource::make($post)->resolve();
}
```

Ответ — JSON, а не Inertia-страница: запрос пришёл обычным XHR через axios, поэтому результат прилетает в `.then(res => ...)`, а страница не перерисовывается.

## 9. Отладка и грабли

- **Отладка запроса.** Временный `dd($request->validated())` в `store()` + вкладка **Network** в DevTools. `Payload` — что улетело, `Preview`/`Response` — что вернулось. Панель DevTools должна быть открыта **до** отправки, иначе запрос не перехватится.
- **Формат даты.** `<input type="date">` шлёт `2026-08-06`, `type="datetime-local"` — `2026-08-06T12:30`. Строгое правило `date_format:Y-m-d H:i:s` такую строку не примет: либо мягкое `date`, либо приводить значение к нужному формату на клиенте перед отправкой.
- **`author_id`** в таблице `posts` NOT NULL, а выбора автора в форме пока нет. На этом уроке значение проставляется временно (жёстко `1` или из текущего пользователя); нормальный выбор автора и категории — отдельная тема.
- **`MassAssignmentException: title is not fillable`** — проверить `$fillable` у модели `Post`: установка Breeze перезаписывает `AppServiceProvider`, и `Model::unguard()`, если он там был, исчезает.
- Повторная отправка той же формы даст ошибку уникальности `title` — это ожидаемо, правило `unique` работает.
