# Lesson 36 - Telescope, политики и журнал запросов

Цель урока: научиться измерять запросы к базе и убирать лишние, перенести правило «удалить пост может только автор» в политику, закрыть web-админку для всех, кроме администраторов, и написать свой журнал запросов на фасаде `DB`. В клиентской части интерфейс почти не меняется: кнопка «Удалить» видна там же, где и раньше. Меняется то, сколько SQL стоит каждый запрос и где записаны правила доступа.

По пути разбираются: **как читать вкладку Queries в Telescope** и что Telescope считает дубликатом; **базовая стоимость запроса** — что база делает до контроллера; **ленивые общие пропсы Inertia** — самая большая находка аудита; **N+1 в валидации** и проверка через `after()` в Form Request; **лишний `exists`** для значений, которые подставил сервер; **политика `PostPolicy`**: `Gate::authorize()` в контроллере и `can()` в ресурсе; **роль или политика** — чем «пускать ли в раздел» отличается от «можно ли с этой записью»; **глобальный middleware** с `DB::listen()` и почему его место — глобальный стек, а не группа `web`; **что журналу нельзя сохранять** — секреты в адресе и в данных запроса.

Отправная точка — состояние после 35-го урока: группы, темы и сообщения тем работают. Telescope уже установлен и собрал данные по всем страницам — на них и строится аудит.

Главная мысль урока: **оптимизировать нужно то, что измерено**. Ленты, группы и чаты в проекте давно без N+1 — это заслуга `with()`, `withCount()` и `withExists()` из прошлых уроков. Telescope нашёл другое: запросы, которые выполняются «на всякий случай» и которые в коде не видны. Счётчик уведомлений считался даже для JSON-ответов, а валидация ходила в базу за каждым участником чата. Политика решает похожую задачу: собирает правило «кто может» в одном месте, чтобы оно не повторялось в нескольких. Но у одного и того же поста два адреса удаления, и закрыть только клиентский мало: вход в админку целиком решает роль, а не политика. Журнал запросов — это маленький свой Telescope. Он пишет одну строку на запрос и может работать там, где настоящий Telescope выключен.

## 1. Карта урока

| Файл | Что меняется |
| --- | --- |
| `config/telescope.php`, `app/Providers/TelescopeServiceProvider.php` | уже созданы `telescope:install`, изучаем без правок |
| `app/Http/Middleware/HandleInertiaRequests.php` | `auth.user` — ленивое замыкание |
| `app/Http/Requests/Client/Chat/StoreRequest.php` | участники проверяются одним запросом в `after()` |
| `app/Http/Requests/Client/Comment/StoreRequest.php` | у `author_id` нет `exists` |
| `app/Http/Requests/Client/Message/StoreRequest.php` | у `author_id` нет `exists` |
| `app/Http/Requests/Client/Repost/StoreRequest.php` | у `author_id` нет `exists` |
| `app/Http/Requests/Client/Theme/StoreRequest.php` | у `author_id` нет `exists` |
| `app/Http/Requests/Client/ThemeMessage/StoreRequest.php` | у `author_id` нет `exists` |
| `app/Http/Requests/Admin/Post/StoreRequest.php` | у `author_id` нет `exists` |
| `app/Policies/PostPolicy.php` | новая политика: `delete()` |
| `app/Http/Controllers/Client/PostController.php` | `destroy()` через `Gate::authorize()`, комментарий в `show()` |
| `app/Http/Resources/Post/PostResource.php` | `can_delete` спрашивает политику |
| `app/Http/Middleware/EnsureUserIsAdmin.php` | проверяет роль у пользователя текущего guard и отвечает `abort(403)` |
| `routes/web.php` | группа `/admin` под `['auth', 'admin']` |
| `routes/api.php` | только закомментированная группа: `auth:api` вместо `jwt.auth` |
| `app/Http/Controllers/Admin/PostController.php` | только комментарий к `destroy()` |
| `database/factories/UserFactory.php` | состояние `admin()` |
| `database/migrations/..._create_access_logs_table.php` | новая: журнал запросов |
| `app/Models/AccessLog.php` | новая модель |
| `app/Http/Middleware/LoggerMiddleware.php` | новый middleware |
| `bootstrap/app.php` | `LoggerMiddleware` в глобальном стеке |
| `tests/Feature/PostPolicyTest.php` | новый набор тестов |
| `tests/Feature/ClientPostDestroyTest.php` | тест флага `can_delete` |
| `tests/Feature/AdminPostDestroyTest.php` | новый набор тестов |
| `tests/Feature/AdminDashboardTest.php` | пользователь теста — администратор |
| `tests/Feature/HandleInertiaRequestsTest.php` | новый набор тестов |
| `tests/Feature/ClientGroupChatTest.php` | тест несуществующего участника |
| `tests/Feature/LoggerMiddlewareTest.php` | новый набор тестов |

Первые три команды из задания **уже выполнены**. Telescope попал в проект вместе с коммитом 34-го урока: пакет есть в `composer.json`, конфиг, провайдер и миграция `telescope_entries` на месте, данные уже собираются.

```
composer require laravel/telescope
php artisan telescope:install
php artisan migrate
```

Команды для этого урока (запускаете вы):

```
php artisan make:policy PostPolicy -m Post
php artisan make:model AccessLog -m
php artisan make:middleware LoggerMiddleware
php artisan make:test PostPolicyTest --phpunit
php artisan make:test AdminPostDestroyTest --phpunit
php artisan make:test HandleInertiaRequestsTest --phpunit
php artisan make:test LoggerMiddlewareTest --phpunit
```

`-m` у `make:policy` — это `--model`, а не миграция, как у `make:model`. С ним заготовка сразу получает методы `viewAny`, `view`, `create`, `update`, `delete`, `restore` и `forceDelete` с тайп-хинтом `Post`.

Имя `LoggerMiddleware` — из команды задания. В коде урока класс называется `AccessLogger`, но это код из другого проекта, как и `ModuleHelper` с путями `/api/v1/analytics`. Модель и таблицу берём из того же кода: `AccessLog` и `access_logs`.

`make:model AccessLog -m` сам назовёт миграцию `create_access_logs_table`. Фабрика модели не нужна: строки журнала в тестах создаёт сам middleware.

После того как миграция заполнена:

```
php artisan migrate
```

---

# Часть I. Telescope

## 2. Что уже установлено

`telescope:install` создал четыре вещи:

- **`config/telescope.php`** — какие «наблюдатели» (watchers) включены: запросы, SQL, модели, кэш, проверки прав и другие. Здесь же порог медленного запроса: `'slow' => 100` миллисекунд;
- **`app/Providers/TelescopeServiceProvider.php`** — фильтр записей и доступ к панели. Фильтр в методе `register()` на `local` пропускает всё. В других окружениях он оставляет только исключения, неудачные запросы и задачи очереди, запуски планировщика и записи с отслеживаемыми тегами;
- **строку в `bootstrap/providers.php`** — без неё провайдер не загрузится;
- **миграцию `telescope_entries`** — Telescope хранит данные в той же базе PostgreSQL.

Панель открывается по адресу `/telescope`. На `local` она доступна всем, в остальных окружениях доступ решает гейт `viewTelescope` в провайдере. В `phpunit.xml` стоит `TELESCOPE_ENABLED=false`, и тесты в Telescope ничего не пишут.

## 3. Как читать Telescope

Путь к запросам страницы: **Requests** → клик по строке → внизу вкладки **Queries**, **Models**, **Gates**.

Вкладка **Queries** — все SQL-запросы одного HTTP-запроса по порядку. В заголовке, например, «14 queries, 2 of which are duplicated».

**Что Telescope считает дубликатом.** Для каждого запроса он берёт SQL **с плейсхолдерами**, без подставленных значений, и считает хеш. Запросы с одинаковым хешем — одной формы:

```sql
select count(*) as "aggregate" from "profiles" where "id" = ?   -- id = 3
select count(*) as "aggregate" from "profiles" where "id" = ?   -- id = 9
select count(*) as "aggregate" from "profiles" where "id" = ?   -- id = 1
```

Это три запроса и два дубликата. Так выглядит N+1: одна форма повторяется столько раз, сколько элементов в списке.

**Models** — сколько моделей Eloquent создано из строк базы. Если там сотни профилей на странице из десяти карточек, где-то загружается целая связь, хотя нужно одно число. Именно так выглядел бы аксессор `is_subscribed` из кода 35-го урока.

**Gates** — проверки прав: какая способность спрошена и какой был ответ. В части II здесь появятся проверки `delete` для постов.

Чтобы сравнить «до» и «после», удобно очищать старые записи:

```
php artisan telescope:clear
```

## 4. Аудит: что показал Telescope

Данные сняты с реальных запросов в Telescope по всем страницам проекта:

| Запрос | SQL | Дубликаты | Вывод |
| --- | --- | --- | --- |
| `GET /feed` | 11 | 0 | N+1 нет |
| `GET /posts/25` | 15 | 0 | норма: `load()`, `loadCount()`, `loadExists()` на одной модели |
| `GET /profiles/1` | 13 | 0 | норма |
| `GET /groups` | 6 | 0 | каталог одним запросом |
| `GET /groups/1` | 10 | 0 | норма |
| `GET /themes/2` | 11 | 0 | норма |
| `GET /chats` | 7 | 0 | норма |
| `GET /chats/2` | 9 | 0 | норма |
| `GET /admin/posts` | 14 | 2 | не проблема, см. ниже |
| `POST /broadcasting/auth` | 6 | 0 | лишний COUNT уведомлений → раздел 5 |
| `GET /profiles?search=` | 6 | 0 | лишний COUNT уведомлений → раздел 5 |
| `POST /posts/25/comments` | 13 | 1 | COUNT дважды → раздел 5; `exists` → раздел 7 |
| `POST /chats` (3 участника) | 10 | 2 | `exists` на каждого участника → раздел 6 |
| `POST /chats/2/messages` | 10 | 0 | разделы 5 и 7 |
| `POST /themes/2/messages` | 11 | 0 | разделы 5 и 7 |
| `POST /groups/1/themes` | 9 | 0 | разделы 5 и 7 |

### Базовая стоимость запроса

Откройте любой запрос — первые строки всегда одинаковые. Лента, `GET /feed`:

```sql
select * from "sessions" where "id" = '...' limit 1          -- сессия: SESSION_DRIVER=database
select * from "users" where "id" = 1 limit 1                 -- middleware auth
select * from "profiles" where "profiles"."user_id" = 1 ...  -- $request->user()->profile
select count(*) as "aggregate" from "app_notifications" ...  -- счётчик колокольчика в шапке
-- ... запросы контроллера ...
update "sessions" set "payload" = '...'                      -- сохранение сессии
```

Четыре-пять запросов делаются ещё до контроллера и после него. Сессия и пользователь — работа фреймворка, и на странице они действительно нужны. Профиль и счётчик нужны шапке. Но не каждый ответ — страница: об этом раздел 5.

### «2 of which are duplicated» на `/admin/posts`

Это скриншот из материалов урока. Дубли там такие:

- **два `select * from "sessions"`** с разными id. В этом запросе старая сессия заменена новой: в списке видны `delete` старой и `insert` новой. Так Laravel делает при входе пользователя. Это разовое событие, а не повтор на каждом запросе;
- **два `select * from "cache"`** с разными ключами: `posts_index_version` и ключ страницы списка. Схема кэша с версией из 25-го урока так и устроена: сначала версия, потом значение.

Одинаковая форма SQL — не всегда ошибка. Дубликат становится проблемой, когда число повторов растёт вместе с данными.

## 5. Находка 1: общие пропсы считаются на каждом запросе

Самый частый запрос в Telescope — `POST /broadcasting/auth`: 58 из 141. Его отправляет Echo, чтобы подписаться на приватный канал. Запросы в нём:

```sql
select * from "sessions" ...
select * from "users" where "id" = 1 limit 1
select * from "profiles" where "profiles"."user_id" = 1 ...
select count(*) as "aggregate" from "app_notifications" ...   -- ← кому?
select * from "profiles" where "id" = '1' limit 1
update "sessions" ...
```

Ответ `/broadcasting/auth` — короткий JSON с подписью канала. Счётчик уведомлений в нём не отправляется, но в базу за ним сходили. То же в каждом JSON-ответе: лайк, комментарий, поиск профилей, сообщение в чате.

**Почему так.** `HandleInertiaRequests` стоит в группе `web`, и его метод `share()` вызывается на **каждом** запросе этой группы, а не только когда отдаётся страница:

```php
'auth' => [
    'user' => $request->user()
        ? AuthUserResource::make($request->user())->resolve()
        : null,
],
```

Правая часть — обычное выражение PHP, и оно вычисляется сразу, при сборке массива. Ресурс достаёт профиль и считает уведомления. Потом контроллер отвечает JSON-ом, и собранное значение просто выбрасывается.

**Решение — замыкание.** Inertia вызывает замыкания в пропсах только тогда, когда собирает страницу. Для JSON-ответа и для редиректа замыкание так и не вызовется.

`app/Http/Middleware/HandleInertiaRequests.php`, метод `share()`:

```php
    public function share(Request $request): array {
        return [
            ...parent::share($request),
            'auth' => [
                // Ресурс вместо модели: $request->user() уехал бы в каждый ответ
                // всеми колонками таблицы, кроме $hidden. Ресурс называет явно,
                // что клиенту можно, и заодно доносит профиль со счётчиком
                // непрочитанных уведомлений для колокольчика в шапке.
                //
                // fn () => — ленивое значение. share() вызывается на КАЖДОМ запросе
                // группы web: на JSON для axios, на редиректах после форм,
                // на /broadcasting/auth. Без замыкания профиль и COUNT уведомлений
                // уходили бы в базу, а ответ их не отправлял. Замыкание Inertia
                // вызовет, только когда собирает страницу.
                //
                // Тернарник обязателен: на странице логина и на главной пользователя
                // нет, а AuthUserResource::make(null) отдал бы пустой объект вместо
                // null — и проверка v-if="$page.props.auth.user" во Welcome.vue
                // перестала бы работать.
                'user' => fn (): ?array => $request->user()
                    ? AuthUserResource::make($request->user())->resolve()
                    : null,
            ],
        ];
    }
```

Во Vue ничего не меняется: на странице `$page.props.auth.user` приходит в том же виде. Замыкание во вложенном массиве Inertia тоже раскроет, отдельный ключ `auth.user` не нужен.

**Бонус — частичные перезагрузки.** `router.reload({ only: ['posts'] })` после удаления поста просит только посты. Раньше `auth.user` всё равно вычислялся, а потом отбрасывался фильтром `only`. Теперь замыкание не вызывается вовсе.

Что изменилось в цифрах:

| Запрос | Было | Стало |
| --- | --- | --- |
| `POST /broadcasting/auth` | 6 | 5 |
| `GET /profiles?search=` | 6 | 5 |
| `GET /posts/25/comments` | 9 | 8 |
| `POST /groups/1/subscribers` | 9 | 8 |
| страницы (`/feed`, `/groups/1`, …) | без изменений | счётчик нужен шапке |

Профиль уходит из запроса не везде: большинству контроллеров он нужен самим (`$request->user()->profile`). Уходит всегда счётчик уведомлений.

## 6. Находка 2: N+1 в валидации участников чата

`POST /chats` с тремя участниками (два выбранных плюс создатель):

```sql
select count(*) as "aggregate" from "profiles" where "id" = 3
select count(*) as "aggregate" from "profiles" where "id" = 9
select count(*) as "aggregate" from "profiles" where "id" = 1
```

Источник — правило в `Chat\StoreRequest`:

```php
'members.*' => ['integer', 'distinct', 'exists:profiles,id'],
```

`members.*` — это правила **для каждого элемента**. Для каждого id валидатор отдельно спрашивает базу. Двадцать участников — двадцать запросов. Ограничения сверху у списка нет, так что запрос с тысячей id дал бы тысячу SELECT-ов.

**Решение — одна проверка на весь список в `after()`.** Метод `after()` у Form Request возвращает проверки, которые выполняются после всех правил. Там можно сделать один запрос `whereIn`.

`app/Http/Requests/Client/Chat/StoreRequest.php`, новые импорты и изменённые правила:

```php
use App\Models\Profile;
use Illuminate\Validation\Validator;
```

```php
    public function rules(): array {
        return [
            // Название обязательно: по нему групповой чат отличается от диалога
            // (docblock модели Chat). max:255 — string() без длины в миграции.
            'title' => ['required', 'string', 'max:255'],
            // min:2 — создатель (его дописал prepareForValidation) и хотя бы
            // один выбранный участник.
            'members' => ['required', 'array', 'min:2'],
            // Правила для каждого элемента массива.
            // distinct — один профиль не попадёт в чат дважды: attach() повторы
            // не проверяет, а unique в chat_profile превратил бы их в 500.
            //
            // exists здесь больше нет: он делал запрос на КАЖДЫЙ элемент.
            // Существование всех профилей проверяет after() одним запросом.
            'members.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Проверки после основных правил.
     *
     * Все ли выбранные профили существуют — одним запросом на весь список,
     * а не запросом на каждого участника, как делал exists в members.*.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array {
        return [
            function (Validator $validator): void {
                // Если members или его элементы уже не прошли правила, в базу
                // не ходим: строка 'abc' в whereKey() на PostgreSQL дала бы
                // 500 «invalid input syntax for type bigint» вместо ошибки формы.
                if ($validator->errors()->hasAny(['members', 'members.*'])) {
                    return;
                }

                $members = $this->input('members');

                // distinct уже гарантировал, что повторов нет: сколько id пришло,
                // столько профилей и должно найтись.
                if (Profile::whereKey($members)->count() !== count($members)) {
                    $validator->errors()->add('members', 'Среди участников есть несуществующий профиль.');
                }
            },
        ];
    }
```

Теперь один запрос при любом числе участников:

```sql
select count(*) as "aggregate" from "profiles" where "profiles"."id" in (3, 9, 1)
```

`POST /chats`: было 7 + N запросов (N — участники вместе с создателем), стало 7.

**Почему не `exists` на сам массив.** Laravel это умеет: `'members' => ['array', 'exists:profiles,id']` проверит весь массив одним `whereIn`. Но правила массива выполняются **раньше** правил его элементов: валидатор раскрывает `members.*` в `members.0`, `members.1` и ставит их в конец списка. Строка `'abc'` дошла бы до PostgreSQL раньше, чем `integer` успел бы её отбраковать, и вместо ошибки в форме пользователь получил бы 500. `after()` выполняется после всех правил, и проверка `hasAny()` пропускает запрос, если в элементах уже есть ошибки.

Тесты идут на SQLite, а ему строка в `where id in (...)` не мешает. Поэтому ловушку с `'abc'` тест не поймает. Её страхует проверка `hasAny()` и этот комментарий.

## 7. Находка 3: `exists` для значения, которое подставил сервер

`POST /chats/2/messages`:

```sql
select "profiles".*, "chat_profile"."chat_id" ...           -- участники: authorize()
select count(*) as "aggregate" from "profiles" where "id" = 3  -- ← exists для author_id
insert into "messages" ...
```

Правило `'author_id' => ['required', 'integer', 'exists:profiles,id']` стоит в шести Form Request: `Comment`, `Message`, `Repost`, `Theme`, `ThemeMessage` и в админском `Post\StoreRequest`. Везде `author_id` не приходит из формы, его подставляет `prepareForValidation()`:

```php
$this->merge([
    'author_id' => $this->user()?->profile?->id,
]);
```

Этот id взят у профиля, который **только что загружен из базы** в том же запросе. Спрашивать базу «существует ли профиль с этим id» — проверять то, что мы и так знаем. Комментарий в коде объяснял `exists` защитой от несуществующего id, который превратится в 500 на INSERT. Для id из формы такая защита нужна, а для id из сессии такой ситуации не бывает.

`required` остаётся: у пользователя без профиля `author_id` станет `null`, и он получит 422, а не 500.

`app/Http/Requests/Client/Comment/StoreRequest.php`, правило и комментарий к нему:

```php
            // exists не нужен: author_id не приходит из формы, его подставляет
            // prepareForValidation() из профиля, который уже загружен из базы.
            // Проверка существования была бы лишним запросом на каждую отправку.
            // required остаётся: у пользователя без профиля здесь null, и это 422.
            'author_id' => ['required', 'integer'],
```

Ту же строку и такой же короткий комментарий получают `Message`, `Repost`, `Theme`, `ThemeMessage` и `Admin\Post\StoreRequest`. Остальные правила не трогаем: `exists:categories,id` в админке и в репосте проверяет id, который пришёл снаружи или скопирован из другой записи.

В docblock-ах `rules()` у `Comment`, `Message` и `Repost` сказано, что «значение от сервера тоже стоит проверить». Эту фразу правим: `required` и `integer` по-прежнему ловят опечатку в коде, а `exists` для значения из загруженной модели ничего не ловит.

Каждая отправка комментария, сообщения, темы и репоста — на запрос меньше:

| Запрос | Было | После разделов 5 и 7 |
| --- | --- | --- |
| `POST /posts/25/comments` | 13 | 11 |
| `POST /chats/2/messages` | 10 | 8 |
| `POST /themes/2/messages` | 11 | 9 |
| `POST /groups/1/themes` | 9 | 7 |

## 8. Что не трогаем и почему

- **`loadCount()` и `loadExists()` на странице поста и группы** — два маленьких подзапроса к одной строке по первичному ключу. Склеить их в один можно, только отказавшись от привязки модели в маршруте. Экономия меньше миллисекунды не стоит отказа от привычного `Post $post`.
- **`parent.author` в ленте** — отдельные запросы за оригиналами репостов и их авторами. Это два запроса на страницу при любом числе карточек. Это не N+1, а стоимость данных, которые показывает карточка.
- **`update "posts" set "views_count" = "views_count" + 1`** на странице поста — самый медленный запрос страницы (около 10 мс). Это запись, а не чтение, и без неё нет счётчика просмотров.
- **Повторная загрузка того, что уже в памяти.** После `$chat->messages()->create()` вызов `$message->load('author')` снова читает профиль смотрящего. На странице профиля `with('author')` загружает сам этот профиль у каждого его поста. Это по одному запросу на запрос. Решения — `setRelation()` и `chaperone()`, см. раздел 24.
- **Два запроса к `sessions`** на каждый запрос — так работает драйвер сессий `database`. С другим драйвером их не будет, но это настройка окружения, а не код урока.

---

# Часть II. Права доступа: политика и роль

## 9. Одно правило в двух местах

Правило «удалить пост может только автор» сейчас записано дважды:

```php
// PostResource: показывать ли кнопку
'can_delete' => $this->author_id === $request->user()?->profile?->id,

// PostController::destroy(): пускать ли запрос
abort_unless($post->author_id === $request->user()->profile?->id, 403);
```

Пока правило простое, копии совпадают. Но правило поменяется, например «автор или модератор». Тогда одну копию легко забыть, и кнопка перестанет совпадать с тем, что разрешит сервер. Хуже, если забудут копию в контроллере.

**Политика** — класс с правилами для одной модели: метод на каждое действие, внутри — ответ `true` или `false`. `PostPolicy::delete()` отвечает, может ли пользователь удалить пост. Спросить её можно из любого места: из контроллера, из ресурса, из маршрута.

**Регистрировать политику не нужно.** Laravel находит её по имени: для `App\Models\Post` он ищет `App\Policies\PostPolicy`.

## 10. `PostPolicy`

`make:policy -m Post` создал семь методов, и все возвращают `false`. Оставляем только `delete()`: остальные действия пока ничего не спрашивают, а заготовка с `return false` выглядела бы как правило, которого нет.

`app/Policies/PostPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

/**
 * Правила доступа к постам.
 *
 * Laravel находит политику сам по имени: App\Models\Post → App\Policies\PostPolicy.
 * Спрашивают её Gate::authorize() в контроллере и $user->can() в ресурсе.
 */
class PostPolicy {
    /**
     * Удалить пост может только его автор.
     *
     * Первым аргументом Laravel передаёт вошедшего пользователя. Гостя он
     * отклонит сам, до вызова метода: тайп-хинт User без ? гостя не принимает.
     *
     * posts.author_id ссылается на профиль, а не на пользователя, поэтому
     * сравниваем с id профиля. У пользователя без профиля слева будет null,
     * и сравнение с author_id (он NOT NULL) даст false.
     */
    public function delete(User $user, Post $post): bool {
        return $user->profile?->id === $post->author_id;
    }
}
```

Импорт `Illuminate\Auth\Access\Response` из заготовки удаляем: ответ политики здесь просто `bool`.

## 11. Контроллер: `Gate::authorize()`

`app/Http/Controllers/Client/PostController.php`, новый импорт и метод `destroy()`:

```php
use Illuminate\Support\Facades\Gate;
```

```php
    /**
     * Удаление своего поста.
     *
     * Алиас HttpResponse в импортах нужен из-за коллизии: Response в этом файле —
     * это Inertia\Response, его возвращает show().
     *
     * @see \App\Http\Controllers\Admin\PostController::destroy()
     */
    public function destroy(Post $post): HttpResponse {
        // Правило «удаляет только автор» живёт в PostPolicy::delete().
        // authorize() спрашивает политику и при отказе бросает исключение,
        // которое Laravel превращает в 403 — abort_unless() больше не нужен.
        //
        // 403, а не 404 как в show(): существование поста уже не тайна,
        // пользователь видел его в ленте.
        Gate::authorize('delete', $post);

        // Тот же сервис, что и в админке. Он снимает связи, стирает файлы картинок
        // и двигает версию кэша списка.
        PostService::destroy($post);

        // 204 No Content: тела у ответа нет, клиент и так знает, что удалял.
        return response()->noContent();
    }
```

`Request $request` из аргументов ушёл: пользователя `Gate` берёт сам.

**Почему не `$this->authorize()`.** В старых версиях Laravel базовый контроллер подключал трейт `AuthorizesRequests`, и в контроллерах писали `$this->authorize('delete', $post)`. В Laravel 11+ `app/Http/Controllers/Controller.php` пустой, и такого метода нет. `Gate::authorize()` делает то же самое, и так написано в коде урока.

## 12. Ресурс: `can_delete` через политику

`app/Http/Resources/Post/PostResource.php`, ключ `can_delete`:

```php
            // Может ли текущий пользователь удалить этот пост.
            //
            // Правило живёт в PostPolicy::delete() — там же, где его проверяет
            // настоящий запрос в PostController::destroy(). Кнопка видна ровно
            // там, где удаление пройдёт, и разойтись этим двум местам негде.
            //
            // can() — метод модели User: находит политику по классу поста и зовёт
            // delete(). ?-> и ?? false — в API-контексте пользователя может не быть.
            //
            // N+1 нет: политика читает $user->profile, а профиль загружается один
            // раз на весь запрос и запоминается на объекте User.
            //
            // Ключ по-прежнему подсказка интерфейсу, а не защита: скрытая кнопка
            // от запроса, собранного руками, не спасает — спасает authorize().
            'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
```

`$this->resource` — сама модель поста внутри ресурса.

В Telescope на вкладке **Gates** страницы ленты теперь видно десять проверок `delete`, по одной на карточку, с ответом `allowed` или `denied`. Это проверки в памяти, SQL-запросов в них нет.

## 13. Vue

Ничего не меняется. `ItemPost.vue` уже показывает кнопку по флагу с сервера:

```vue
<DeletePost v-if="postData.can_delete" :post="postData" class="ml-auto" @deleted="handleDeleted" />
```

Поменялся только источник ответа: раньше флаг считало сравнение в ресурсе, теперь политика.

## 14. Админка: вход только для администратора

Политика закрыла удаление в клиенте. Но у поста есть второй адрес удаления — `DELETE /admin/posts/{post}` из таблицы в админке. Группа `/admin` в `routes/web.php` закрыта только `auth`. Любой вошедший пользователь может открыть админку по адресу и удалить, отредактировать или создать любой пост.

**Почему не политика.** `Gate::authorize('delete', $post)` в админском `destroy()` с правилом «удаляет автор» запретил бы администратору удалять чужие посты, а это и есть работа админки. Добавить в политику `before()` «администратору можно всё» тоже плохо:

- это закрыло бы только удаление: редактирование, создание и список постов в админке остались бы открыты всем;
- `before()` вызывается при каждой проверке `can()`, в том числе в ленте. Роли загружались бы на каждой странице с карточками, а администратор увидел бы «Удалить» у всех постов в клиентской ленте.

Здесь два разных вопроса:

| | Роль (middleware) | Политика |
| --- | --- | --- |
| Вопрос | пускать ли пользователя в раздел | можно ли ему это действие с этой записью |
| Пример | «админка — только администраторам» | «удалить пост может его автор» |
| Где проверяется | маршрут или группа маршрутов | контроллер, ресурс |

Админка — раздел целиком, поэтому её закрывает роль.

### `EnsureUserIsAdmin`

Middleware с проверкой роли в проекте уже есть — alias `admin` в `bootstrap/app.php`. Он писался для API: брал пользователя из guard `api` и отвечал JSON-ом. Используется он только в закомментированной группе `routes/api.php`. Переводим его на пользователя текущего guard.

`app/Http/Middleware/EnsureUserIsAdmin.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin {
    /**
     * Пропускает запрос дальше только для администратора.
     *
     * Ставится после middleware аутентификации: auth для web-админки,
     * auth:api для API. Оба запоминают guard, через который вошёл пользователь,
     * и $request->user() без аргумента найдёт его и по сессии, и по JWT-токену.
     *
     * is_admin — аксессор User::isAdmin(): есть ли роль admin среди ролей
     * пользователя. Это один запрос к roles на запрос в админку.
     *
     * abort(403), а не response()->json(): Laravel сам ответит страницей ошибки
     * браузеру и JSON-ом тому, кто просит JSON (axios, API).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response {
        abort_unless($request->user()?->is_admin, 403);

        return $next($request);
    }
}
```

Раньше внутри был `$request->user('api')`: группа API использовала `jwt.auth`, а этот middleware проверяет токен, но не переключает guard по умолчанию. Штатный `auth:api` переключает — старый комментарий в middleware как раз советовал на него перейти. Закомментированная строка в `routes/api.php` правится под это:

```php
// Route::group(['middleware' => ['auth:api', 'admin']], function () {
```

### Маршруты

`routes/web.php`, комментарий и первая строка группы админки:

```php
// Админка закрыта двумя middleware.
//
// auth — код внутри опирается на текущего пользователя (author_id берётся
// из auth()->user()->profile), поэтому гость сюда попасть не должен.
// Незалогиненного Authenticate редиректит на route('login') — страницу поставил Breeze.
//
// admin — вошедший пользователь без роли admin получит 403. Роль, а не политика:
// вопрос «пускать ли в раздел» решается для всей группы сразу, а что можно делать
// с конкретной записью, решают политики.
Route::middleware(['auth', 'admin'])->group(function () {
```

Порядок `auth`, потом `admin`: сначала узнать, кто пришёл, потом проверить его роль. Маршруты внутри группы не меняются: роль закрывает все сразу — список, создание, редактирование и удаление.

### Контроллер

`app/Http/Controllers/Admin/PostController.php`, конец docblock-а `destroy()`, вместо абзаца «Авторизации пока нет…»:

```php
     * Удалять посты может только администратор: всю группу /admin закрывает
     * middleware admin (routes/web.php). PostPolicy::delete() здесь не вызываем:
     * её правило «удаляет только автор» для админки не подходит — администратор
     * удаляет и чужие посты.
```

### Фабрика: пользователь-администратор

Тестам админки теперь нужен пользователь с ролью. Добавляем состояние фабрики, как `unverified()` в той же фабрике.

`database/factories/UserFactory.php`, импорт и метод после `unverified()`:

```php
use App\Models\Role;
```

```php
    /**
     * Пользователь с ролью admin.
     *
     * afterCreating — роль привязывается к уже сохранённому пользователю:
     * у несохранённого нет id для role_user.
     *
     * firstOrCreate — роль admin одна на всю базу, даже если в тесте несколько
     * администраторов. Уникального индекса у roles.title нет, и create() завёл бы дубли.
     */
    public function admin(): static {
        return $this->afterCreating(function (User $user): void {
            $user->roles()->attach(Role::firstOrCreate(['title' => 'admin']));
        });
    }
```

`UserFactory` создан генератором Breeze, скобки в нём стоят на новой строке. После правки `pint --dirty` приведёт к стилю проекта весь файл — это ожидаемо.

**Перед проверкой в браузере** убедитесь, что у вашего пользователя роль есть, иначе админка ответит 403 и вам:

```sql
select u.id, u.email
from users u
join role_user ru on ru.user_id = u.id
join roles r on r.id = ru.role_id
where r.title = 'admin';
```

Роль выдаёт тестовому пользователю `DatabaseSeeder`.

## 15. Отличия от урока

На уроке:

```php
public function delete(User $user, Post $post): bool
{
    return (int) $user->profile->id === (int) $post->profile_id;
}
```

```php
public function destroy(Post $post)
{
    Gate::authorize('delete', $post);
    $post->delete();
    return response()->json(['message' => 'success'], Response::HTTP_OK);
}
```

```vue
<deletePost v-if="this.$page.props.auth.user.profile.id === post.profile_id" :post="post"></deletePost>
```

- **`profile_id` → `author_id`.** В нашей схеме колонка автора поста — `author_id`.
- **`$user->profile->id` без `?->`.** У пользователя без профиля это ошибка 500 вместо отказа. С `?->` политика просто вернёт `false`.
- **Приведения `(int)`.** PDO отдаёт id из PostgreSQL и SQLite числами. Строгое `===` в проекте давно работает без приведений: так устроены `can_delete` и `abort_unless()` из прошлых уроков.
- **Сравнение в шаблоне Vue.** На уроке правило записано второй раз, теперь на клиенте, и политика защищает только сервер. У нас кнопку показывает флаг `can_delete`, а флаг считает та же политика.
- **`$post->delete()` и `200` с `message`.** Остаются `PostService::destroy()` (связи, файлы, кэш) и `204` из прошлых уроков.
- **Админка.** В уроке про неё ничего не сказано. У нас группу `/admin` закрывает роль (раздел 14): иначе клиентская кнопка защищена политикой, а тот же пост удаляется через админку любым вошедшим пользователем.

---

# Часть III. Журнал запросов

## 16. Задача

Telescope — инструмент разработчика. Он пишет десятки строк на один запрос, и в продакшене его обычно выключают. Журнал из урока скромнее: **одна строка на HTTP-запрос**. В ней записано, кто пришёл, куда, с каким ответом и сколько SQL это стоило. Такой журнал можно оставить включённым всегда и потом искать в нём «самые тяжёлые страницы» обычным SQL-запросом.

Раз журнал живёт долго и читают его не только разработчики, у него есть главное ограничение: **никаких значений из запроса**. Ни паролей, ни токенов, ни текстов личных сообщений. Вместо адреса хранится шаблон маршрута, вместо данных — имена полей (раздел 18).

Как он устроен:

```
запрос → LoggerMiddleware::handle()
           ├─ DB::listen(): после каждого SQL — +1 к счётчикам
           ├─ $next($request): сессия, auth, контроллер, ответ
           └─ AccessLog::create(): одна строка в access_logs
```

`DB::listen()` регистрирует функцию, которую Laravel вызывает после каждого выполненного SQL-запроса. В неё приходит объект `QueryExecuted`: SQL с плейсхолдерами `?`, значения и время в миллисекундах.

## 17. Таблица и модель

`database/migrations/..._create_access_logs_table.php`:

```php
    public function up(): void {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            // Когда пришёл запрос. Отдельная колонка вместо timestamps():
            // запись журнала не редактируется, и updated_at ей не нужен.
            $table->dateTime('datetime');
            // Раздел приложения: client, admin или api (LoggerMiddleware::module()).
            $table->string('module');
            // Кто сделал запрос. nullable — у гостя пользователя нет.
            // nullOnDelete: удаление пользователя не должно упираться в его журнал —
            // записи останутся, ссылка на пользователя обнулится.
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            // Подробности: метод, шаблон пути, имена полей, ответ и статистика SQL.
            // jsonb, а не json: PostgreSQL хранит его разобранным, и по ключам
            // внутри можно искать: data->>'path', data->'sql'->>'total_sql'.
            $table->jsonb('data');
            // Главный сценарий чтения — «журнал раздела за период».
            $table->index(['datetime', 'module']);
        });
    }
```

`down()` из заготовки — `Schema::dropIfExists('access_logs')`, его не трогаем.

`app/Models/AccessLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Запись журнала запросов: одна строка на HTTP-запрос.
 *
 * Пишет её только LoggerMiddleware. Связи user() нет: читать журнал
 * через Eloquent пока некому, а колонка user_id для SQL-запросов уже есть.
 */
class AccessLog extends Model {
    /**
     * created_at и updated_at в таблице нет: время запроса лежит в datetime.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'datetime',
        'module',
        'user_id',
        'data',
    ];

    /**
     * data — массив в PHP и JSON в базе. Каст array сам сделает json_encode
     * при записи и json_decode при чтении.
     *
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'datetime' => 'datetime',
            'data' => 'array',
        ];
    }
}
```

## 18. `LoggerMiddleware`

`app/Http/Middleware/LoggerMiddleware.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\AccessLog;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Журнал запросов: одна строка access_logs на каждый HTTP-запрос.
 *
 * Кроме того, кто и куда пришёл, сохраняет статистику SQL за весь запрос.
 * Зарегистрирован в глобальном стеке (bootstrap/app.php), чтобы видеть
 * и запросы сессии, и вход пользователя, и привязку моделей.
 *
 * Значений из запроса журнал не хранит: ни адреса целиком, ни данных формы,
 * ни сообщений исключений. В них бывают пароли, токены и личные сообщения.
 */
class LoggerMiddleware {
    /**
     * Служебные адреса: их вызывает не пользователь, а фронтенд или Telescope.
     *
     * @var list<string>
     */
    private const EXCLUDED_PATHS = [
        // Панель Telescope опрашивает сервер каждые несколько секунд.
        'telescope*',
        // Echo авторизует каждый приватный канал: 58 запросов из 141 в Telescope.
        'broadcasting/auth',
    ];

    /**
     * Типы SQL, которые считаем по отдельности.
     *
     * @var list<string>
     */
    private const SQL_TYPES = ['select', 'insert', 'update', 'delete'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response {
        // Исключения проверяем до DB::listen(): для служебного адреса
        // и слушатель заводить незачем.
        if ($request->is(...self::EXCLUDED_PATHS)) {
            return $next($request);
        }

        $sql = [
            'select' => 0,
            'insert' => 0,
            'update' => 0,
            'delete' => 0,
            'total_sql' => 0,
            'total_time_ms' => 0.0,
            'log' => [],
        ];

        // Функция вызывается после каждого SQL-запроса приложения.
        // &$sql — по ссылке: без & замыкание меняло бы свою копию массива,
        // и после $next() здесь остались бы нули.
        DB::listen(function (QueryExecuted $query) use (&$sql): void {
            // Тип — первое слово запроса.
            $type = Str::lower(Str::before(ltrim($query->sql), ' '));

            if (in_array($type, self::SQL_TYPES, true)) {
                $sql[$type]++;
            }

            $sql['total_sql']++;
            $sql['total_time_ms'] += $query->time;

            // Ключ — SQL с плейсхолдерами ?, без значений. Запросы одной формы
            // складываются в одну строку: count > 1 — тот же «duplicated»,
            // что показывает Telescope.
            $sql['log'][$query->sql]['count'] = ($sql['log'][$query->sql]['count'] ?? 0) + 1;
            $sql['log'][$query->sql]['time_ms'] = ($sql['log'][$query->sql]['time_ms'] ?? 0) + $query->time;
        });

        $response = $next($request);

        // Всё ниже выполняется уже после контроллера: известны маршрут,
        // пользователь, статус ответа и все SQL-запросы.
        $module = $this->module($request);

        // exception есть у ответов, собранных из исключения: 403, 404, 422, 500.
        // ?? null — у файловых и потоковых ответов такого свойства нет вовсе.
        $exception = $response->exception ?? null;

        // INSERT самой этой записи в статистику не попадёт: массив $sql
        // копируется в data раньше, чем запрос выполнится.
        AccessLog::create([
            'datetime' => now(),
            'module' => $module,
            // Guard по умолчанию — web: он знает пользователя по сессии.
            // Пользователя API с JWT-токеном знает только guard api,
            // и $request->user() без аргумента вернул бы для него null.
            'user_id' => ($module === 'api' ? $request->user('api') : $request->user())?->id,
            'data' => [
                'method' => $request->method(),
                // Шаблон маршрута, а не настоящий адрес: reset-password/{token},
                // а не reset-password/9f86d08…. Токен сброса пароля приходит прямо
                // в адресе, и документация Laravel называет его секретным.
                // Заодно все страницы постов складываются в одну строку posts/{post}.
                //
                // У запроса без маршрута (404 на несуществующий адрес) шаблона нет,
                // будет null. Сырой адрес не пишем: в нём может оказаться что угодно.
                'path' => $request->route()?->uri(),
                // Только имена полей, без значений. Значения — это пароли, токены
                // и тексты личных сообщений. Список «что скрыть» всегда отстаёт
                // от новых форм, а понять, что пришло в запросе, помогают и имена.
                'fields' => array_keys($request->all()),
                'status_code' => $response->getStatusCode(),
                // Только класс. Сообщение исключения тоже несёт значения:
                // у QueryException в нём SQL с подставленными данными.
                'exception' => $exception ? $exception::class : null,
                'ip_address' => $request->ip(),
                'sql' => $sql,
            ],
        ]);

        return $response;
    }

    /**
     * Раздел приложения по адресу запроса.
     *
     * Всё, что не API и не админка, — клиентская часть: лента, профили,
     * чаты, группы, а заодно вход и регистрация от Breeze.
     */
    private function module(Request $request): string {
        return match (true) {
            $request->is('api/*') => 'api',
            $request->is('admin/*') => 'admin',
            default => 'client',
        };
    }
}
```

Пример того, что ляжет в `data` для `GET /feed`:

```json
{
    "method": "GET",
    "path": "feed",
    "fields": [],
    "status_code": 200,
    "exception": null,
    "ip_address": "127.0.0.1",
    "sql": {
        "select": 9, "insert": 0, "update": 1, "delete": 0,
        "total_sql": 11, "total_time_ms": 25.59,
        "log": {
            "select count(*) as \"aggregate\" from \"posts\" where \"status\" = ?": { "count": 1, "time_ms": 2.37 },
            "...": {}
        }
    }
}
```

А так — отправка формы нового пароля с неверным токеном, `POST /reset-password`:

```json
{
    "method": "POST",
    "path": "reset-password",
    "fields": ["token", "email", "password", "password_confirmation"],
    "status_code": 302,
    "exception": "Illuminate\\Validation\\ValidationException",
    "ip_address": "127.0.0.1",
    "sql": { "...": {} }
}
```

Видно, какие поля пришли и чем закончился запрос. Токена и пароля в журнале нет. Для той же формы, открытой по ссылке из письма, `path` будет `reset-password/{token}`.

## 19. Регистрация: глобальный стек

`bootstrap/app.php`, новый импорт и первая строка в `withMiddleware()`:

```php
use App\Http\Middleware\LoggerMiddleware;
```

```php
    ->withMiddleware(function (Middleware $middleware): void {
        // Журнал запросов — в глобальный стек, а не в группу web: так он
        // оборачивает весь запрос, включая сессию, вход и привязку моделей,
        // и видит все их SQL. web и api получают его одинаково.
        $middleware->append(LoggerMiddleware::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
```

**Почему не `$middleware->web(append: ...)`.** Middleware выполняются по порядку, как матрёшка. Запросы сессии, пользователя и привязки модели делают `StartSession`, `auth` и `SubstituteBindings`. Laravel всегда ставит их раньше middleware, добавленных в конец группы. Логгер в группе `web` стоял бы внутри них, завёл бы `DB::listen()` слишком поздно и не увидел бы этих запросов. Журнал показывал бы на четыре-пять запросов меньше, чем Telescope. Глобальный middleware стоит снаружи всего этого и видит весь запрос.

Глобальный стек заодно покрывает и `web`, и `api` одной строкой — это и нужно по заданию.

После подключения в Telescope у каждого запроса появится ещё одна строка: `insert into "access_logs"`. Это цена журнала. Сам журнал этот INSERT не считает, поэтому его `total_sql` на единицу меньше, чем число в Telescope.

## 20. Отличия от урока

- **Логируются web и api**, кроме служебных путей. На уроке — только API: строка `if ($type == 'web') return $response`. У нас основная работа с сайтом идёт через web, и журнал только по API был бы почти пустым. Исключения проверяются до `DB::listen()`, а не после ответа.
- **`module` — раздел `client` / `admin` / `api`.** `ModuleHelper` из урока в проекте нет. Отдельный `type` (`web`/`api`) не пишем: раздел `api` уже отвечает на этот вопрос.
- **Тип SQL — первое слово запроса.** `strpos($sql, 'select')` из урока ищет слово в любом месте. `update ... where id in (select ...)` засчитался бы как `select`, потому что проверка `select` идёт первой.
- **Время в миллисекундах** (`total_time_ms`), как в Telescope, а не в секундах. Цифры журнала и панели сравниваются напрямую, и единица записана в имени ключа.
- **Шаблон пути и имена полей вместо адреса и значений.** На уроке пишутся `getPathInfo()` и `$request->all()` без двух полей пароля. Так в журнал попали бы токен сброса пароля из адреса `reset-password/{token}` и из поля `token`, текущий пароль из формы смены пароля, тексты личных сообщений. Любой список «что скрыть» отстаёт от новых форм, поэтому значений не пишем вовсе.
- **`exception` — только класс, без сообщения и трассировки.** Сообщение может содержать данные запроса: у `QueryException` в нём SQL с подставленными значениями. Трассировка занимает килобайты на строку. При ошибке 500 и то и другое есть в `storage/logs/laravel.log` и в Telescope. Условие — «ответ собран из исключения», а не «статус не 200/201»: иначе `204` и редиректы попадали бы в ошибки.
- **Пользователь API — через `user('api')`.** На уроке `Auth::user()`: он смотрит в guard по умолчанию, у нас это `web`, и пользователь с JWT-токеном записался бы как гость.
- **Каст `array` вместо `json_encode()`.** С кастом и ручным `json_encode()` вместе данные закодировались бы дважды: в jsonb лежала бы одна строка, а не объект.
- **Миграция в стиле проекта**: анонимный класс, `foreignId()->constrained()` вместо ручного `foreign()`. Копипаста имени ключа `command_launches_user_id_fk` ушла. Добавлен `nullOnDelete()`, и `data` сделана NOT NULL: middleware заполняет её всегда.

---

# Часть IV. Тесты и проверка

## 21. Тесты

Тесты идут на SQLite в памяти, Telescope в них выключен (`phpunit.xml`).

### `PostPolicyTest`

Полная матрица правила — на уровне политики. HTTP-тесты в `ClientPostDestroyTest` уже проверяют, что контроллер политику вызывает: автор удаляет, посторонний получает 403. Отказ по другой причине они различить не могут.

`tests/Feature/PostPolicyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostPolicyTest extends TestCase {
    use RefreshDatabase;

    /**
     * Лишний пользователь в начале нужен, чтобы id пользователя и id профиля
     * автора разошлись. Иначе фабрики дадут обоим одинаковый id, и политика,
     * сравнивающая author_id с $user->id вместо профиля, прошла бы тест.
     */
    public function test_author_can_delete_own_post(): void {
        User::factory()->create();
        $author = Profile::factory()->create();
        $post = Post::factory()->for($author, 'author')->create();

        $this->assertTrue($author->user->can('delete', $post));
    }

    public function test_other_profile_cannot_delete_post(): void {
        $post = Post::factory()->create();
        $stranger = Profile::factory()->create();

        $this->assertFalse($stranger->user->can('delete', $post));
    }

    /**
     * Без ?-> в политике тест упадёт с «Attempt to read property "id" on null».
     */
    public function test_user_without_profile_cannot_delete_post(): void {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->assertFalse($user->can('delete', $post));
    }
}
```

### `ClientPostDestroyTest`: флаг `can_delete`

Два существующих теста остаются без изменений. Добавляем тест: кнопку показывает та же политика. Он упадёт, если в ресурсе останется старое сравнение с ошибкой или `can()` спросит не ту способность.

`tests/Feature/ClientPostDestroyTest.php`, новый тест:

```php
    public function test_feed_marks_only_own_posts_as_deletable(): void {
        $viewer = Profile::factory()->create();
        // Даты заданы явно: лента сортирует по published_at, и порядок
        // карточек в ответе должен быть известен заранее.
        $foreign = Post::factory()->create([
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $own = Post::factory()->for($viewer, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($viewer->user)->get(route('client.feed.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Client/Feed/Index')
            ->where('posts.data.0.id', $foreign->id)
            ->where('posts.data.0.can_delete', false)
            ->where('posts.data.1.id', $own->id)
            ->where('posts.data.1.can_delete', true)
            ->etc());
    }
```

### `AdminPostDestroyTest`

Удаление через админку: администратор удаляет любой пост, пользователь без роли получает 403. Второй тест упадёт, если группа `/admin` снова останется под одним `auth`.

`tests/Feature/AdminPostDestroyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPostDestroyTest extends TestCase {
    use RefreshDatabase;

    /**
     * Пост чужой: в админке правила «только свой» нет, решает роль.
     */
    public function test_admin_deletes_any_post(): void {
        $post = Post::factory()->create();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->deleteJson(route('admin.posts.destroy', $post));

        $response->assertNoContent();
        $this->assertModelMissing($post);
    }

    /**
     * Без middleware admin любой вошедший пользователь удалил бы так
     * чужой пост, минуя политику клиентской части.
     */
    public function test_user_without_admin_role_cannot_delete_post(): void {
        $post = Post::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->deleteJson(route('admin.posts.destroy', $post));

        $response->assertForbidden();
        $this->assertModelExists($post);
    }
}
```

Один отказ по роли на одном маршруте доказывает, что группа подключила middleware. Остальные маршруты админки стоят в той же группе, отдельный тест на каждый не нужен.

### `AdminDashboardTest`

Существующий тест открывает админку обычным пользователем. После урока он получит 403, поэтому пользователь теста становится администратором:

```php
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard.index'))
```

### `HandleInertiaRequestsTest`

Два поведения ленивого пропса: страница его получает, а JSON-ответ не платит за него запросом.

`tests/Feature/HandleInertiaRequestsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HandleInertiaRequestsTest extends TestCase {
    use RefreshDatabase;

    /**
     * Проп стал замыканием — страница всё равно должна получить значение.
     * Прочитанное уведомление в счёт не входит.
     */
    public function test_page_receives_unread_notifications_count(): void {
        $viewer = Profile::factory()->create();
        Notification::factory()->count(2)->for($viewer)->create();
        Notification::factory()->for($viewer)->create(['read_at' => now()]);

        $response = $this->actingAs($viewer->user)->get(route('client.feed.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('auth.user.profile.notifications_count', 2)
            ->etc());
    }

    /**
     * Главная находка Telescope. Если из share() уберут fn () =>, счётчик
     * снова будет считаться для каждого JSON-ответа, и тест упадёт,
     * показав лишний SQL.
     */
    public function test_json_request_does_not_count_notifications(): void {
        $viewer = Profile::factory()->create();

        DB::enableQueryLog();

        // Поиск профилей — JSON для axios, уведомлений он не создаёт.
        $this->actingAs($viewer->user)
            ->getJson(route('client.profiles.index'))
            ->assertOk();

        $notificationQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'app_notifications'))
            ->values()
            ->all();

        $this->assertSame([], $notificationQueries);
    }
}
```

`assertSame([], ...)`, а не `assertEmpty()`: при падении PHPUnit покажет сам лишний SQL.

### `ClientGroupChatTest`: несуществующий участник

До урока этот случай ловил `exists` в `members.*`, но теста на него не было. Теперь это наш код в `after()`, и без теста его легко потерять: несуществующий id дойдёт до `attach()` и станет 500 от внешнего ключа.

`tests/Feature/ClientGroupChatTest.php`, новый тест:

```php
    public function test_group_chat_rejects_nonexistent_member(): void {
        $viewer = Profile::factory()->create();
        $member = Profile::factory()->create();

        $response = $this->actingAs($viewer->user)
            ->post(route('client.chats.store'), [
                'title' => 'Работа',
                'members' => [$member->id, 999999],
            ]);

        $response->assertSessionHasErrors([
            'members' => 'Среди участников есть несуществующий профиль.',
        ]);
        $this->assertDatabaseCount('chats', 0);
    }
```

Удаление `exists` у `author_id` отдельного теста не требует. «Автор из сессии» уже проверяют `ClientCommentTest::test_comment_author_is_taken_from_session` и `ClientMessageTest::test_message_author_is_taken_from_session`, а ветку «профиля нет → 422» держит `required`, которое не менялось.

### `LoggerMiddlewareTest`

Проверяем: запрос попадает в журнал вместе с SQL контроллера; секрет из адреса и значения полей не сохраняются; раздел определяется по адресу; у запроса API записан пользователь из токена; ошибка записывается; служебные пути пропускаются.

`tests/Feature/LoggerMiddlewareTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class LoggerMiddlewareTest extends TestCase {
    use RefreshDatabase;

    public function test_request_is_logged_with_its_sql_queries(): void {
        $viewer = Profile::factory()->create();

        $this->actingAs($viewer->user)->get(route('client.feed.index'));

        $log = AccessLog::sole();

        $this->assertSame('client', $log->module);
        $this->assertSame($viewer->user_id, $log->user_id);
        $this->assertSame('GET', $log->data['method']);
        $this->assertSame('feed', $log->data['path']);
        $this->assertSame(200, $log->data['status_code']);

        // Счётчик пагинации ленты — запрос контроллера. Раз он в журнале,
        // слушатель видел SQL всего запроса, а не только своего кода.
        $feedCount = $log->data['sql']['log']['select count(*) as "aggregate" from "posts" where "status" = ?'];
        $this->assertSame(1, $feedCount['count']);
    }

    /**
     * Токен сброса пароля приходит прямо в адресе: reset-password/{token}.
     * Упадёт, если в path пишется $request->path(), а не шаблон маршрута.
     */
    public function test_secret_from_url_is_not_logged(): void {
        $this->get(route('password.reset', 'secret-reset-token'));

        $data = AccessLog::sole()->data;

        $this->assertSame('reset-password/{token}', $data['path']);
        $this->assertStringNotContainsString('secret-reset-token', json_encode($data));
    }

    /**
     * Та же форма отправляет токен и пароль в теле запроса. Журнал знает,
     * какие поля пришли, но не их значения.
     */
    public function test_request_values_are_not_logged(): void {
        $this->post(route('password.store'), [
            'token' => 'secret-reset-token',
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $data = AccessLog::sole()->data;

        $this->assertSame(['token', 'email', 'password', 'password_confirmation'], $data['fields']);
        $this->assertStringNotContainsString('secret-reset-token', json_encode($data));
        $this->assertStringNotContainsString('secret-password', json_encode($data));
    }

    /**
     * Гость: админка ответит редиректом на вход, API — списком. Раздел
     * от ответа не зависит, только от адреса.
     *
     * TestWith — один тест, запущенный с разными данными: у случаев
     * одинаковые подготовка и проверки.
     */
    #[TestWith(['/admin/posts', 'admin'])]
    #[TestWith(['/api/categories', 'api'])]
    public function test_module_is_detected_by_path(string $path, string $module): void {
        $this->get($path);

        $this->assertSame($module, AccessLog::sole()->module);
    }

    /**
     * Guard по умолчанию — web. Упадёт, если журнал берёт пользователя
     * через $request->user(): для запроса с JWT-токеном там null.
     *
     * fromUser() только выпускает токен и не запоминает пользователя в guard:
     * найти его журнал сможет, лишь разобрав заголовок Authorization.
     */
    public function test_api_request_is_logged_with_token_user(): void {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)->getJson('/api/categories');

        $this->assertSame($user->id, AccessLog::sole()->user_id);
    }

    public function test_failed_request_logs_exception_class(): void {
        $this->getJson('/api/categories/999999');

        $data = AccessLog::sole()->data;

        $this->assertSame(404, $data['status_code']);
        $this->assertSame(ModelNotFoundException::class, $data['exception']);
    }

    public function test_service_paths_are_not_logged(): void {
        $this->postJson('/broadcasting/auth');

        $this->assertDatabaseCount('access_logs', 0);
    }
}
```

**Чего здесь нет и почему.**

- **Количества запросов «ровно N».** Число SQL меняется с любым `with()` в контроллере. Тест проверяет, что журнал видит запрос контроллера, а не сколько их всего.
- **Каждого типа SQL по отдельности**: это одна строка разбора первого слова, её хватает проверки через ленту.
- **Числа запросов в `POST /chats`**: `after()` проверяет по поведению — несуществующий участник даёт ошибку формы. Что запрос один, видно в Telescope (раздел 22).
- **Отключения журнала в тестах.** Он пишет строку на каждый HTTP-запрос теста, но это один INSERT в SQLite в памяти. Без middleware `LoggerMiddlewareTest` проверял бы то, чего нет в работающем приложении.

## 22. Проверка

1. **До правок кода** пройтись по страницам: лента, пост, профиль, группа, тема, чат. Отправить комментарий и сообщение, создать групповой чат. В Telescope записать число запросов — должно совпасть с таблицей раздела 4.
2. Запустить команды из раздела 1, заполнить файлы, `php artisan migrate` — появилась таблица `access_logs`.
3. `php artisan telescope:clear` и повторить шаг 1.
4. `POST /broadcasting/auth` (приходит сам при открытии чата) — в Queries нет `app_notifications`.
5. Отправить комментарий — нет ни `count(*) ... "app_notifications"` от общих пропсов, ни `count(*) ... "profiles" where "id" = ?`. Вместо этого есть `insert into "access_logs"`.
6. Создать групповой чат с двумя участниками — один запрос `... "profiles"."id" in (...)` вместо запроса на каждого.
7. Открыть ленту: число запросов прежнее (+1 `insert into "access_logs"`), колокольчик в шапке показывает счётчик. На вкладке **Gates** — проверки `delete` с `allowed` у своих постов и `denied` у чужих.
8. Кнопка «Удалить» видна только у своих постов. Удалить свой — пост пропал из ленты.
9. Проверить отказ на сервере: на чужом посте в DevTools → Console выполнить `axios.delete('/posts/ID')` с id чужого поста — ответ `403`, пост на месте.
10. Проверить роль запросом из раздела 14 — ваш пользователь в списке. Открыть `/admin/posts` — список на месте, удаление чужого поста работает.
11. Войти вторым пользователем без роли в другом браузере. `/admin/posts` — страница 403. В консоли на любой странице сайта `axios.delete('/admin/posts/ID')` — ответ `403`, пост на месте.
12. Посмотреть журнал:

    ```sql
    select datetime, module, user_id,
           data->>'method' as method, data->>'path' as path,
           data->>'status_code' as status,
           data->'sql'->>'total_sql' as total_sql
    from access_logs
    order by id desc
    limit 20;
    ```

    Для страницы ленты `total_sql` на единицу меньше, чем число в Telescope: INSERT самой записи журнал не считает. Строк для `/telescope/...` и `/broadcasting/auth` нет. У страниц постов `path` — `posts/{post}`, без номера.
13. Самые тяжёлые адреса за день — то, ради чего журнал и нужен. Благодаря шаблону пути все страницы постов складываются в одну строку:

    ```sql
    select data->>'path' as path, max((data->'sql'->>'total_sql')::int) as max_sql
    from access_logs
    where datetime > now() - interval '1 day'
    group by 1
    order by 2 desc
    limit 10;
    ```

14. Выйти, на странице входа «Forgot your password?» → отправить форму с любой почтой. В журнале у `POST /forgot-password` поле `fields` — `["email"]`, самой почты нет. Если в `.env` настроена почта, открыть ссылку из письма: `path` — `reset-password/{token}`, токена в строке нет.
15. `php artisan test --filter=PostPolicyTest` — три теста зелёные.
16. `php artisan test --filter=ClientPostDestroyTest` — три теста зелёные.
17. `php artisan test --filter=AdminPostDestroyTest` — два теста зелёные.
18. `php artisan test --filter=AdminDashboardTest` — тест зелёный.
19. `php artisan test --filter=HandleInertiaRequestsTest` — два теста зелёные.
20. `php artisan test --filter=ClientGroupChatTest` — шесть тестов зелёные.
21. `php artisan test --filter=LoggerMiddlewareTest` — семь тестов, восемь запусков (у `TestWith` два набора данных).
22. `php artisan test` — весь набор зелёный.
23. `vendor/bin/pint --dirty` — правок стиля нет. Заготовки `make:policy`, `make:model` и `make:middleware`, а заодно `UserFactory` пишут скобки не в стиле проекта, Pint их поправит.

## 23. Грабли

- **Telescope показывает старые цифры** — в списке запросов смотрите верхние строки или очистите записи через `php artisan telescope:clear`.
- **Колокольчик в шапке всегда 0** — в замыкании потерян `->resolve()`. Ресурс без него Inertia развернёт сам, с обёрткой `data`, и счётчик окажется в `auth.user.data.profile`. Ловит `test_page_receives_unread_notifications_count`.
- **`count(*) ... "app_notifications"` всё ещё в JSON-запросах** — `fn () =>` не добавлен, или добавлен не в `HandleInertiaRequests`. Ловит `test_json_request_does_not_count_notifications`.
- **500 `invalid input syntax for type bigint`** при создании чата — `exists` поставлен на массив `members`, а не проверяется в `after()`. Или в `after()` нет раннего `return` при ошибках в `members.*`.
- **«Среди участников есть несуществующий профиль» для правильных участников** — из `members.*` убран `distinct`: с повтором id профилей находится меньше, чем пришло элементов.
- **Несуществующий участник даёт 500 `FOREIGN KEY constraint failed`** — `exists` из `members.*` убран, а `after()` не добавлен. Ловит `test_group_chat_rejects_nonexistent_member`.
- **Автор не может удалить свой пост, 403** — политика сравнивает `$post->author_id` с `$user->id` вместо `$user->profile?->id`. На свежей базе id пользователя и профиля часто совпадают, и ошибка проявляется не сразу. Ловит `test_author_can_delete_own_post`.
- **403 у всех, хотя политика написана верно** — файл не в `app/Policies` или класс назван не `PostPolicy`. Laravel не нашёл политику, проверок для `delete` нет, и доступ запрещён.
- **`Call to undefined method ...::authorize()`** — в контроллере написано `$this->authorize()`, как в старых версиях Laravel. Нужно `Gate::authorize()`.
- **`Attempt to read property "id" on null`** в политике — `$user->profile->id` без `?->`, как в коде урока. Ловит `test_user_without_profile_cannot_delete_post`.
- **У всех постов кнопка «Удалить» или ни у одного** — в `can()` передан не пост (`$this` вместо `$this->resource`) или спрошена не та способность.
- **В `sql` журнала одни нули** — в `use (&$sql)` потерян `&`.
- **`total_sql` в журнале на 4–5 меньше, чем в Telescope** — логгер зарегистрирован в `$middleware->web(append: ...)`, а не через `$middleware->append()`.
- **`access_logs` пустая** — middleware не зарегистрирован в `bootstrap/app.php`, или не выполнена миграция.
- **Журнал забит строками `/telescope/telescope-api/...`** — пропущен `EXCLUDED_PATHS`.
- **В `data` лежит строка с экранированными кавычками вместо объекта** — `json_encode()` из урока вместе с кастом `array`: данные закодированы дважды.
- **`Undefined property: ...::$exception`** — `$response->exception` без `?? null` на ответе-файле (`BinaryFileResponse`).
- **`SQLSTATE[23503]` при удалении пользователя** — у `user_id` нет `nullOnDelete()`.
- **`make:policy PostPolicy -m Post` создал миграцию?** Нет: у `make:policy` флаг `-m` — это модель. Если миграция всё же появилась, запускалась команда `make:model`.
- **Администратор получает 403 в админке** — у пользователя нет роли `admin` (запрос из раздела 14), или в `EnsureUserIsAdmin` остался `$request->user('api')`: у web-админки такого пользователя нет.
- **Вошедший пользователь без роли по-прежнему удаляет посты в админке** — в `routes/web.php` у группы остался один `auth`. Ловит `test_user_without_admin_role_cannot_delete_post`.
- **`AdminDashboardTest` упал с 403** — пользователь теста создан без `admin()`.
- **`Call to undefined method Database\Factories\UserFactory::admin()`** — состояние не добавлено в фабрику.
- **Админка отвечает JSON-ом `{"message": "forbidden"}` вместо страницы 403** — в `EnsureUserIsAdmin` остался старый `response()->json()`.
- **API-группа с `admin` всегда отвечает 403** — перед `admin` стоит `jwt.auth`, а не `auth:api`: guard по умолчанию не переключился, и `$request->user()` пустой.
- **В журнале виден токен сброса пароля** — `path` взят из `$request->path()` вместо `$request->route()?->uri()`. Ловит `test_secret_from_url_is_not_logged`.
- **В журнале видны пароли и тексты сообщений** — в `fields` пишутся `$request->all()` или `except()` вместо `array_keys($request->all())`. Ловит `test_request_values_are_not_logged`.
- **`path` пустой (`null`)** — у запроса нет маршрута: это 404 на несуществующий адрес. Так и задумано.
- **`user_id` пустой у запросов API с токеном** — в журнале `$request->user()` без `'api'`. Ловит `test_api_request_is_logged_with_token_user`.
- **`test_api_request_is_logged_with_token_user` падает с ошибкой JWT о секрете** — в `.env` нет `JWT_SECRET`: его создаёт `php artisan jwt:secret`.

## 24. Что можно сделать лучше

**Не загружать то, что уже в памяти.** В `ChatController::storeMessage()`, `ThemeController::storeMessage()` и `CommentController::store()` после `create()` стоит `$message->load('author')`. Это снова `select * from "profiles" where "id" in (...)`, хотя автор — смотрящий, и его профиль уже есть. Замена: `$message->setRelation('author', $request->user()->profile)`. На странице профиля у всех постов автор — сам этот профиль. `chaperone('author')` в `Profile::posts()` подставит его в каждый пост без запроса, и `author` можно убрать из `with()`.

**`PostPolicy::view()`.** Правило «кому виден пост» записано в `PostController::show()` и в `CommentController::abortUnlessPostVisible()`. В политике оно стало бы одним методом, а 404 вместо 403 даёт `Response::denyAsNotFound()`.

**Id записей в журнале.** Из шаблона `posts/{post}` не видно, какой именно пост открыли или удалили. Можно сохранять ключи привязанных моделей: `$request->route()->parameters()`, и только те значения, что стали моделями. Строковые параметры вроде `{token}` при этом пропускаются сами.

**Очистка Telescope.** Таблица `telescope_entries` растёт на десятки строк за каждый клик. В `routes/console.php`:

```php
Schedule::command('telescope:prune --hours=48')->daily();
```

**Очистка журнала.** Трейт `MassPrunable` в `AccessLog` с методом `prunable()` («старше 30 дней») и `Schedule::command('model:prune')->daily()`.

**Запись после ответа.** У middleware может быть метод `terminate()`: Laravel вызывает его после того, как ответ ушёл в браузер. INSERT журнала перестал бы задерживать ответ. Данные из `handle()` в `terminate()` придётся передать через атрибуты запроса.

**Защита от N+1 при разработке.** `Model::preventLazyLoading(! app()->isProduction())` в `AppServiceProvider::boot()`. Ленивая загрузка связи у модели из коллекции сразу бросит исключение, и N+1 будет видно без Telescope.

**Драйвер сессий.** С `SESSION_DRIVER=redis` или `file` из каждого запроса уходят два SQL к `sessions`.

**Страница журнала в админке.** Таблица «самые тяжёлые адреса» по запросу из раздела 22 с фильтром по разделу и дате.

## Задание

- `composer require laravel/telescope`
- `php artisan telescope:install`
- `php artisan migrate`
- `php artisan make:policy PostPolicy -m Post`

1. Сделать оптимизацию всех запросов в *Telescope*.
2. Сделать ограничение прав пользователя через *Policy* на примере кнопки удаления поста.
3. Написать логгер c использованием фасада *DB* (код — middleware `AccessLogger` и миграция `access_logs` из материалов урока): `php artisan make:middleware LoggerMiddleware`.

**Решения, принятые при подготовке урока:**

- из находок Telescope исправляются три: ленивые общие пропсы, N+1 в валидации участников чата, лишний `exists` для `author_id` из сессии. Повторная загрузка того, что уже в памяти (`setRelation()`, `chaperone()`), вынесена в «Что можно сделать лучше»;
- `exists` для `author_id` убран во всех Form Request, где id подставляет сервер: пять клиентских и админский `Post\StoreRequest`;
- политика применяется к удалению поста в клиентской части; `view()` в неё не переносится;
- web-админка закрыта ролью: группа `/admin` под `['auth', 'admin']`, `EnsureUserIsAdmin` берёт пользователя текущего guard. Политика в админке не вызывается — администратор удаляет любые посты;
- журнал пишет запросы web и api, кроме `telescope*` и `broadcasting/auth`; middleware зарегистрирован в глобальном стеке;
- журнал не хранит значений из запроса: вместо адреса — шаблон маршрута, вместо данных — имена полей, из исключения — только класс;
- пользователь запроса API берётся через `$request->user('api')`, остальных — через guard по умолчанию;
- колонка `module` — раздел приложения: `client`, `admin` или `api`;
- имя middleware — `LoggerMiddleware` из команды задания, модель и таблица — `AccessLog` и `access_logs` из кода урока.
