# Lesson 31 - Чаты

Цель урока: на странице чужого профиля рядом с «Подписаться» появляется кнопка «Написать». Нажатие открывает страницу чата с этим человеком. Если чат с ним уже был, открывается существующий; если не было, создаётся новый. Сообщений пока нет: только чат, его участники и пустая страница.

По пути разбираются: **схема «чат + участники»** и почему сообщения не хранят пару «отправитель → получатель»; **pivot-таблица по конвенции** (`chat_profile`, без единого аргумента в связи — в отличие от `profile_subscriptions`); **`attach()`** и чем он отличается от `toggle()`; **поиск по связи `whereHas()`** — «мой чат, в котором есть он»; **транзакция** на двух вставках; **`<Link method="post">` и редирект** как ответ на Inertia-запрос (вместо axios, как у подписки); **проверка доступа** к странице чата.

Отправная точка — состояние после 30-го урока.

Главная мысль урока: **чат — отдельная сущность, к которой присоединены профили**. Сообщение принадлежит чату, а не паре «кто → кому». Отсюда всё остальное: вопрос «есть ли у нас с ним чат» — это запрос к участникам, а страница чата — обычная страница ресурса со своим id.

## 1. Карта урока

| Файл | Что меняется |
| --- | --- |
| `database/migrations/..._create_chats_table.php` | новая: чаты |
| `database/migrations/..._create_messages_table.php` | новая: сообщения (схема сейчас, отправка — позже) |
| `database/migrations/..._create_chat_profile_table.php` | новая: участники чатов (pivot) |
| `app/Models/Chat.php` | новая модель: `profiles()`, `messages()` |
| `app/Models/Message.php` | новая модель: `chat()`, `author()` |
| `app/Models/Profile.php` | связь `chats()` |
| `database/factories/ChatFactory.php` | новая: нужна тестам |
| `routes/client.php` | маршруты `client.profiles.chats.store` и `client.chats.show` |
| `app/Http/Controllers/Client/ProfileController.php` | метод `storeChat()`: найти или создать чат |
| `app/Http/Controllers/Client/ChatController.php` | новый: `show()` |
| `app/Http/Resources/Chat/ChatResource.php` | новый: чат с участниками и заголовком |
| `app/Http/Resources/Profile/ProfileResource.php` | флаг `can_message` |
| `resources/js/Pages/Client/Profile/Show.vue` | кнопка «Написать» |
| `resources/js/Pages/Client/Chat/Show.vue` | новая страница чата |
| `tests/Feature/ClientChatTest.php` | новый набор тестов |

Команды для генерации файлов (запускаете вы, **именно в этом порядке** — почему, сказано в разделе 3):

```
php artisan make:model Chat -m
php artisan make:model Message -m
php artisan make:migration create_chat_profile_table
php artisan make:factory ChatFactory --model=Chat
php artisan make:controller Client/ChatController
php artisan make:resource Chat/ChatResource
php artisan make:test ClientChatTest --phpunit
```

`make:migration create_chat_profile_table` сам поймёт, что это создание таблицы `chat_profile`: имя вида `create_…_table` Artisan разбирает и подставляет `Schema::create('chat_profile')` в заготовку.

Первые две команды — как в задании. Фабрику для `Chat` создаём отдельной командой, поэтому трейт `HasFactory` в модель придётся дописать руками (раздел 4). Если удобнее, первую команду можно запустить как `make:model Chat -mf` — тогда фабрика и `HasFactory` появятся сами, а четвёртая команда не нужна.

И после того, как миграции заполнены:

```
php artisan migrate
```

---

# Часть I. Схема

## 2. Задача: как хранить переписку

Напрашивается самая короткая схема — одна таблица `messages` с колонками `sender_id` и `recipient_id`. Для «написать человеку» её хватает, но она начинает мешать почти сразу:

| Вопрос | `messages(sender_id, recipient_id)` | `chats` + `chat_profile` + `messages(chat_id)` |
| --- | --- | --- |
| «Есть ли у нас с ним переписка?» | поиск по двум колонкам в обе стороны: `(я, он)` или `(он, я)` | «мой чат, где участвует он» — один `whereHas` |
| «Список моих диалогов» | группировка сообщений по неупорядоченной паре — неудобный запрос | `$profile->chats` |
| Название, аватар, настройки диалога | положить некуда | колонки у `chats` |
| Чат на троих | схему придётся переделывать | ещё одна строка в `chat_profile` |

Поэтому переписка раскладывается на три таблицы:

```
 profiles ──< chat_profile >── chats ──< messages
                (участники)              (сообщения)
```

- **`chats`** — сама переписка;
- **`chat_profile`** — кто в ней участвует (многие ко многим: у профиля много чатов, у чата много участников);
- **`messages`** — сообщения, каждое принадлежит одному чату и имеет автора.

Адресата у сообщения нет вовсе: его читают все участники чата.

## 3. Миграции

Порядок команд из раздела 1 важен: `chat_profile` и `messages` ссылаются на `chats` внешним ключом, значит таблица `chats` должна создаться первой. Миграции выполняются в порядке имён файлов, а имя начинается с даты и времени создания — отсюда «сначала `make:model Chat`».

### `chats`

```php
    public function up(): void {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            // Название чата. nullable: у диалога двух людей своего названия нет,
            // на странице вместо него показываем собеседника (раздел 9).
            $table->string('title')->nullable();
            $table->timestamps();
        });
    }
```

Колонка `title` — как на уроке. В этом уроке её никто не заполняет, но она уже пригодится ресурсу: «название есть — показываем его, нет — показываем собеседника».

### `chat_profile`

```php
    public function up(): void {
        Schema::create('chat_profile', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->index()->constrained('chats');
            $table->foreignId('profile_id')->index()->constrained('profiles');
            $table->timestamps();
            // Профиль участвует в чате один раз. attach(), в отличие от toggle(),
            // дубли не проверяет: повторный вызов молча записал бы вторую строку.
            $table->unique(['chat_id', 'profile_id']);
        });
    }
```

**Имя таблицы — по конвенции Laravel для многие-ко-многим:** обе модели в единственном числе, в алфавитном порядке, через подчёркивание. `Chat` + `Profile` → `chat_profile`. Раз имя совпало с конвенцией, связь в модели не потребует ни одного аргумента (раздел 4). Для сравнения: у подписок конвенция дала бы `profile_profile`, поэтому там таблица названа по смыслу — `profile_subscriptions`, и в `Profile::subscribers()` все имена указаны руками.

**`id()` в pivot-таблице** — как у `likeables` и `profile_subscriptions`: единообразно со всем проектом. `belongsToMany` он не мешает.

**`unique(['chat_id', 'profile_id'])`** защищает от повторного участника **внутри одного** чата. От второго чата с той же парой профилей он не защищает — это разные `chat_id`. Эта проверка — задача контроллера (раздел 7).

### `messages`

```php
    public function up(): void {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            // Чат, которому принадлежит сообщение. Не «получатель»: сообщение
            // адресовано всем участникам чата.
            $table->foreignId('chat_id')->index()->constrained('chats');
            // Автор — профиль. Имя author_id, а не profile_id: так названы авторы
            // у posts и comments. Таблица указана явно — из author_id Laravel
            // вывел бы authors.
            $table->foreignId('author_id')->index()->constrained('profiles');
            $table->text('content');
            $table->timestamps();
        });
    }
```

Сообщения в этом уроке не создаются, но таблицу заполняем сразу: команда `make:model Message -m` есть в задании, а пустая миграция означала бы через урок либо новую миграцию `add_…_to_messages_table`, либо `migrate:fresh`. Набор колонок минимальный и повторяет соглашения проекта: автор — `author_id`, текст — `content`, как у постов и комментариев.

Каскадного удаления (`->cascadeOnDelete()`) нет ни у одной из трёх таблиц — как и во всём проекте.

## 4. Модели и связи

### `Chat`

`app/Models/Chat.php`:

```php
<?php

namespace App\Models;

use Database\Factories\ChatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model {
    /** @use HasFactory<ChatFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
    ];

    /**
     * Участники чата (многие ко многим через chat_profile).
     *
     * Аргументов нет: имя таблицы chat_profile и колонки chat_id / profile_id
     * Laravel выводит из имён моделей. Сравните с Profile::subscribers(),
     * где конвенция не подошла и все имена пришлось перечислить.
     *
     * withTimestamps() — чтобы attach() заполнял created_at и updated_at:
     * по умолчанию Eloquent даты в промежуточной таблице не трогает.
     */
    public function profiles(): BelongsToMany {
        return $this->belongsToMany(Profile::class)->withTimestamps();
    }

    /**
     * Сообщения чата (внешний ключ messages.chat_id).
     */
    public function messages(): HasMany {
        return $this->hasMany(Message::class);
    }
}
```

`HasFactory` дописываем руками: `make:model` без флага `-f` трейт не добавляет, а без него `Chat::factory()` в тестах упадёт с «Call to undefined method».

`HasLog` не подключаем, как и у `Notification`: логировать каждое открытие чата незачем.

### `Message`

`app/Models/Message.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model {
    /**
     * chat_id в списке нет: сообщения будут создаваться через связь
     * $chat->messages()->create([...]), и ключ чата она подставит сама.
     *
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'content',
    ];

    /**
     * Чат, которому принадлежит сообщение.
     */
    public function chat(): BelongsTo {
        return $this->belongsTo(Chat::class);
    }

    /**
     * Автор сообщения (внешний ключ messages.author_id).
     */
    public function author(): BelongsTo {
        return $this->belongsTo(Profile::class, 'author_id');
    }
}
```

Фабрики у `Message` пока нет: создавать сообщения в тестах незачем. Она появится вместе с отправкой сообщений.

### `Profile::chats()`

`app/Models/Profile.php`:

```php
    /**
     * Чаты, в которых участвует профиль (многие ко многим через chat_profile).
     *
     * Обратная сторона Chat::profiles(): та же таблица, те же колонки
     * и снова без аргументов.
     */
    public function chats(): BelongsToMany {
        return $this->belongsToMany(Chat::class)->withTimestamps();
    }
```

Модели над pivot-строкой (`->using(...)`, как у `Like` в 30-м уроке) здесь нет: событий на «профиль добавлен в чат» нам не нужно, а без них модель — лишний файл.

### Фабрика

`database/factories/ChatFactory.php`:

```php
    public function definition(): array {
        return [
            // По умолчанию — диалог: своего названия у него нет.
            'title' => null,
        ];
    }
```

Участников фабрика не создаёт: в тестах их добавляют через связь, `$chat->profiles()->attach([...])`, — так видно, кто именно в чате.

---

# Часть II. Кнопка «Написать»

## 5. Маршруты

`routes/client.php`, импорт `ChatController` в начало файла и два маршрута:

```php
    // Кнопка «Написать» на странице профиля. Адрес читается как «чаты с этим
    // профилем», POST — «открой мне такой».
    //
    // Имя store, хотя чат создаётся не всегда: если он уже есть, сервер просто
    // перенаправит в него. Клиенту это знать не нужно — решает сервер,
    // как и у переключения подписки.
    Route::post('profiles/{profile}/chats', [ProfileController::class, 'storeChat'])
        ->whereNumber('profile')
        ->name('client.profiles.chats.store');

    // Страница чата. Адрес НЕ вложен в профиль: у чата свой id и несколько
    // участников, «чей» это чат, из адреса не скажешь. Тот же довод,
    // что у comments/{comment}/likes.
    Route::get('chats/{chat}', [ChatController::class, 'show'])
        ->whereNumber('chat')
        ->name('client.chats.show');
```

Методы разнесены по двум контроллерам, как на уроке. Создание чата начинается со страницы профиля и живёт рядом с `toggleSubscribe()`, как «действие над профилем». Страница чата — самостоятельный ресурс: дальше в неё придут сообщения, отправка, список чатов, и всё это место в `ChatController`, а не в разросшемся `ProfileController`.

## 6. Кнопка на странице профиля: `<Link method="post">`

У подписки запрос уходит через axios: страница остаётся на месте, меняется одна надпись. У «Написать» результат другой — **переход на другую страницу**. Для этого axios не подходит: он получил бы редирект, молча прошёл по нему и принёс в JavaScript HTML страницы чата, а адресная строка осталась бы прежней.

Правильный инструмент здесь — Inertia-ссылка с методом POST:

```
клик ─► Inertia: POST /profiles/7/chats   (заголовок X-Inertia)
     ─► сервер: нашёл или создал чат, ответил 302 → /chats/5
     ─► Inertia сама выполняет GET /chats/5
     ─► страница Client/Chat/Show, в адресной строке /chats/5, «Назад» работает
```

Вся навигация — одна строка разметки, без обработчиков в `<script>`.

`resources/js/Pages/Client/Profile/Show.vue` — кнопок в шапке профиля стало две, поэтому появляется общая обёртка:

```vue
        <!--
            Обёртка появилась, потому что кнопок стало две: shrink-0 переехал
            с кнопки подписки на неё.
        -->
        <div class="flex shrink-0 gap-2">
            <!--
                Link, а не axios, как у подписки: после нажатия нужно оказаться
                на странице чата. Inertia отправит POST, получит от сервера
                редирект и сама откроет страницу, на которую он ведёт.

                method="post" — маршрут принимает только POST; без атрибута
                ссылка ушла бы GET-запросом и получила 405.
                as="button" — действие, а не переход по адресу: <a> с POST
                нельзя открыть в новой вкладке, и Inertia просит рисовать <button>.
            -->
            <Link
                v-if="profile.can_message"
                :href="route('client.profiles.chats.store', profile.id)"
                method="post"
                as="button"
                class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                Написать
            </Link>

            <button
                v-if="profile.can_subscribe"
                type="button"
                :disabled="isSending"
                class="rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50"
                :class="
                    isSubscribed
                        ? 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                        : 'border-sky-700 bg-sky-700 text-white hover:bg-sky-800'
                "
                @click="toggleSubscribe"
            >
                {{ isSubscribed ? 'Отписаться' : 'Подписаться' }}
            </button>
        </div>
```

`Link` на этой странице уже импортирован (для пагинации), в `<script>` ничего не меняется. Надпись на кнопке — «Написать»: интерфейс проекта на русском, как и «Подписаться».

Флаг `can_message` — в `app/Http/Resources/Profile/ProfileResource.php`, рядом с `can_subscribe`:

```php
            // Показывать ли кнопку «Написать»: писать самому себе нельзя.
            //
            // Правило пока совпадает с can_subscribe, но флаги отдельные: кнопки
            // разные, и шаблон, где «Написать» спрятана под can_subscribe, читался
            // бы как ошибка. Как и can_subscribe, это подсказка интерфейсу, а не
            // защита: настоящая проверка стоит в storeChat().
            'can_message' => $this->id !== $request->user()?->profile?->id,
```

## 7. `storeChat()`: найти или создать

Пункт 2 задания: если чат с этим пользователем уже есть — просто перейти в него, если нет — создать. В видео урока `storeChat()` создаёт новый чат при каждом нажатии; ниже — версия с проверкой.

`app/Http/Controllers/Client/ProfileController.php`:

```php
    /**
     * Кнопка «Написать»: открыть чат с этим профилем.
     *
     * Чат уже есть — ведём в него, нет — создаём и ведём в новый. Кнопка одна
     * на оба случая: был ли у пользователя диалог с этим человеком, решает
     * сервер, а не интерфейс.
     *
     * Возвращает редирект, а не массив: запрос отправляет Inertia-ссылка,
     * и по редиректу она сама откроет страницу чата.
     */
    public function storeChat(Request $request, Profile $profile): RedirectResponse {
        $viewer = $request->user()->profile;

        // Те же две проверки, что в toggleSubscribe(): без профиля участвовать
        // в чате некому, а чат с самим собой не нужен. Скрытая кнопка
        // от POST-запроса руками не защищает.
        abort_if($viewer === null, 403, 'У пользователя нет профиля.');
        abort_if($viewer->id === $profile->id, 403, 'Нельзя написать самому себе.');

        // «Среди МОИХ чатов — тот, в котором участвует ОН».
        // chats() сужает выборку до чатов смотрящего, whereHas() оставляет
        // только те, где среди участников есть второй профиль.
        $chat = $viewer->chats()
            ->whereHas('profiles', fn (Builder $query) => $query->whereKey($profile->id))
            ->first();

        if ($chat === null) {
            // Чат и его участники — вставки в две разные таблицы. Если вторая
            // упадёт, без транзакции в базе останется чат без участников:
            // его не найдёт ни поиск выше, ни страница чата. Транзакция
            // откатит обе вставки разом — как в PostService.
            $chat = DB::transaction(function () use ($viewer, $profile): Chat {
                $chat = Chat::create();

                // attach() с массивом id — один INSERT на обе строки chat_profile.
                $chat->profiles()->attach([$viewer->id, $profile->id]);

                return $chat;
            });
        }

        // В route() передаём модель, а не $chat->id: Laravel сам возьмёт ключ.
        return redirect()->route('client.chats.show', $chat);
    }
```

Новые импорты: `App\Models\Chat`, `Illuminate\Http\RedirectResponse`, `Illuminate\Support\Facades\DB`. `Builder` в файле уже есть.

Разбор главного.

**Как работает поиск.** Запрос строится от связи, как и везде в проекте. Получается примерно такой SQL:

```sql
select chats.* from chats
inner join chat_profile on chats.id = chat_profile.chat_id
where chat_profile.profile_id = :viewer          -- мои чаты: это даёт $viewer->chats()
  and exists (                                   -- и в них есть он: это whereHas()
      select * from profiles
      inner join chat_profile on profiles.id = chat_profile.profile_id
      where chats.id = chat_profile.chat_id and profiles.id = :profile
  )
limit 1
```

Проверка симметрична: неважно, кто первым нажал «Написать». Если чат создал собеседник, на моё нажатие найдётся тот же чат, потому что в `chat_profile` нет «инициатора», есть только участники.

**Почему не `firstOrCreate()`.** Он ищет по колонкам **одной** таблицы: «чат с `title = X`». Условие «чат, где участвуют эти два профиля» лежит в другой таблице, и готового метода для него нет. Поэтому код такой, как на уроке: найти → если `null`, создать.

**Про групповые чаты.** Сейчас каждый чат — диалог двух людей, и поиска по двум участникам достаточно. Если однажды появятся чаты на троих, этот запрос найдёт и групповой чат, где мы оба состоим. Тогда условие дополняется одной строкой: `->has('profiles', '=', 2)` («ровно два участника»).

## 8. Как это выглядит в браузере

Если открыть DevTools → Network и нажать «Написать», будет видно два запроса:

1. `POST /profiles/7/chats` — ответ `302`, заголовок `Location: /chats/5`;
2. `GET /chats/5` — ответ JSON страницы с `component: "Client/Chat/Show"`.

Второй запрос делает не наш код, а Inertia: браузер прошёл по редиректу, и Inertia отрисовала компонент, указанный в ответе. Нажмите кнопку ещё раз — первый запрос вернёт тот же `/chats/5`, а в таблице `chats` новой строки не появится.

---

# Часть III. Страница чата

## 9. `ChatController::show()` и доступ к чату

`app/Http/Controllers/Client/ChatController.php`:

```php
<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\ChatResource;
use App\Models\Chat;
use Illuminate\Http\Request;
use Inertia\Response;

class ChatController extends Controller {
    /**
     * Страница чата.
     *
     * Chat $chat — неявная привязка модели: несуществующий id даёт 404
     * до контроллера.
     */
    public function show(Request $request, Chat $chat): Response {
        // Участники нужны дважды: для проверки доступа и для ресурса.
        // Загружаем их один раз, проверка ниже идёт по готовой коллекции
        // без отдельного запроса.
        $chat->load('profiles');

        // Чат видят только его участники: адрес /chats/5 легко набрать руками.
        //
        // 403, а не 404, как у чужого поста на модерации: номера чатов идут
        // подряд, и скрывать сам факт существования чата незачем. Честный отказ —
        // то же, что позже вернёт политика (ChatPolicy::view()), когда политики
        // появятся в курсе. Пока проверка живёт в контроллере — осознанно.
        //
        // Если у пользователя нет профиля, в contains() уйдёт null: среди
        // участников он не найдётся, и ответ будет тем же 403.
        abort_unless($chat->profiles->contains($request->user()->profile), 403);

        return inertia('Client/Chat/Show', [
            'chat' => ChatResource::make($chat)->resolve(),
        ]);
    }
}
```

На уроке в контроллере используется `compact('chat')`. В проекте пропсы везде передаются явным массивом, так и оставляем.

`$chat->profiles->contains(...)` без скобок: здесь нужна **уже загруженная коллекция**, а не новый запрос. Это обратная ситуация к аксессору из 30-го урока: там был нужен `COUNT` в базе, здесь данные уже в памяти.

## 10. `ChatResource`

`app/Http/Resources/Chat/ChatResource.php`:

```php
<?php

namespace App\Http\Resources\Chat;

use App\Http\Resources\Profile\ProfileResource;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatResource extends JsonResource {
    /**
     * Чат для страницы чата: заголовок и участники.
     *
     * Ресурс рассчитан на загруженную связь profiles: из участников собирается
     * заголовок, поэтому whenLoaded() здесь не нужен — без участников чат
     * показать нельзя. Контроллер обязан сделать load('profiles'), иначе
     * связь догрузится лишним запросом.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            // Своё название есть не у каждого чата: у диалога title пустой,
            // и тогда заголовком служат ники собеседников — всех участников,
            // кроме смотрящего.
            //
            // Собирает сервер, а не шаблон: клиенту нужна готовая строка, как
            // готовая ссылка в NotificationResource.
            'title' => $this->title ?? $this->profiles
                ->reject(fn (Profile $profile): bool => $profile->id === $request->user()?->profile?->id)
                ->pluck('nickname')
                ->join(', '),
            // Вложенный ресурс разворачиваем через resolve(), иначе на клиенте
            // появится лишняя обёртка data — как с автором в PostResource.
            'profiles' => ProfileResource::collection($this->profiles)->resolve(),
        ];
    }
}
```

`reject()`, `pluck()`, `join()` — методы коллекции, а не запросы: участники уже в памяти, база здесь не участвует.

## 11. `Client/Chat/Show.vue`

`resources/js/Pages/Client/Chat/Show.vue` — файл создаётся руками, генератора для Vue-страниц нет:

```vue
<template>
    <Head :title="chat.title" />

    <section class="mb-6 rounded-lg bg-white p-5 shadow">
        <h1 class="text-2xl font-semibold text-gray-900">{{ chat.title }}</h1>

        <!-- Участники со ссылками на профили. Себя тоже показываем: это честный состав чата. -->
        <ul class="mt-3 flex flex-wrap gap-2">
            <li v-for="profile in chat.profiles" :key="profile.id">
                <Link
                    :href="route('client.profiles.show', profile.id)"
                    class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600 hover:bg-gray-200"
                >
                    {{ profile.nickname }}
                </Link>
            </li>
        </ul>
    </section>

    <!-- Сообщения появятся в следующем уроке; пока только пустое состояние. -->
    <p class="rounded-lg bg-white p-6 text-sm text-gray-500">Сообщений пока нет.</p>
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link },
    props: {
        // required: true: страница без чата не имеет смысла.
        chat: {
            type: Object,
            required: true,
        },
    },
};
</script>
```

Путь `Client/Chat/Show` — тот, что указан в `inertia()` в контроллере: Inertia ищет компонент в `resources/js/Pages` по этой строке, опечатка в любой из частей даст пустой экран и ошибку в консоли.

---

# Часть IV. Тесты и проверка

## 12. Тесты

```
php artisan make:test ClientChatTest --phpunit
```

Проверяем: нажатие создаёт чат с обоими участниками; повторное нажатие ведёт в существующий; чат с **другим** человеком не подхватывается; себе написать нельзя; страницу чата видят только участники.

```php
<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientChatTest extends TestCase {
    use RefreshDatabase;

    public function test_message_button_creates_chat_with_both_profiles(): void {
        $viewer = Profile::factory()->create();
        $author = Profile::factory()->create();

        // post(), а не postJson(): кнопка отправляет обычный запрос и ждёт
        // редирект, JSON в ответе не предполагается.
        $response = $this->actingAs($viewer->user)
            ->post(route('client.profiles.chats.store', $author));

        // sole() — «ровно одна запись, иначе исключение»: заодно проверяет,
        // что чат создан один.
        $chat = Chat::sole();

        $response->assertRedirect(route('client.chats.show', $chat));

        $this->assertDatabaseHas('chat_profile', ['chat_id' => $chat->id, 'profile_id' => $viewer->id]);
        $this->assertDatabaseHas('chat_profile', ['chat_id' => $chat->id, 'profile_id' => $author->id]);
    }

    public function test_existing_chat_is_reused(): void {
        $viewer = Profile::factory()->create();
        $author = Profile::factory()->create();

        // Участников добавляем напрямую через связь: тест проверяет повторное
        // нажатие, а не создание чата.
        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $author->id]);

        $this->actingAs($viewer->user)
            ->post(route('client.profiles.chats.store', $author))
            ->assertRedirect(route('client.chats.show', $chat));

        $this->assertDatabaseCount('chats', 1);
    }

    /**
     * Самая вероятная ошибка в storeChat() — потерять whereHas() и взять
     * «первый попавшийся мой чат». Предыдущий тест её не заметит: там чат
     * у смотрящего один, и он как раз нужный.
     */
    public function test_chat_with_another_profile_is_not_reused(): void {
        $viewer = Profile::factory()->create();
        $author = Profile::factory()->create();

        $otherChat = Chat::factory()->create();
        $otherChat->profiles()->attach([$viewer->id, Profile::factory()->create()->id]);

        $this->actingAs($viewer->user)
            ->post(route('client.profiles.chats.store', $author))
            ->assertRedirect();

        $this->assertDatabaseCount('chats', 2);
        $this->assertDatabaseHas('chat_profile', ['profile_id' => $author->id]);
    }

    public function test_user_cannot_start_chat_with_himself(): void {
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->post(route('client.profiles.chats.store', $profile))
            ->assertForbidden();

        $this->assertDatabaseCount('chats', 0);
    }

    public function test_participant_opens_chat_page(): void {
        $viewer = Profile::factory()->create();
        $author = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $author->id]);

        // Заголовок диалога — ник собеседника, а не свой: проверяем именно это.
        $this->actingAs($viewer->user)
            ->get(route('client.chats.show', $chat))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Chat/Show')
                ->where('chat.title', $author->nickname)
                ->has('chat.profiles', 2)
                ->etc());
    }

    public function test_stranger_cannot_open_foreign_chat(): void {
        $chat = Chat::factory()->create();
        $chat->profiles()->attach([
            Profile::factory()->create()->id,
            Profile::factory()->create()->id,
        ]);

        $this->actingAs(Profile::factory()->create()->user)
            ->get(route('client.chats.show', $chat))
            ->assertForbidden();
    }
}
```

**Чего здесь нет и почему.** Нет теста на пользователя без профиля: ветка `abort_if($viewer === null, ...)` та же, что у подписки и лайков, и отдельной логики чата не содержит. Нет теста на флаг `can_message`: это подсказка интерфейсу, настоящую защиту проверяет `test_user_cannot_start_chat_with_himself`.

## 13. Проверка

1. Запустить команды из раздела 1, заполнить миграции, `php artisan migrate` — появились таблицы `chats`, `messages`, `chat_profile`.
2. Открыть чужой профиль (`/profiles/{id}`) — рядом с «Подписаться» кнопка «Написать». На своём профиле по тому же адресу кнопок нет.
3. Нажать «Написать» — адрес сменился на `/chats/N`, заголовок — ник собеседника, в списке участников два профиля.
4. В базе: одна строка в `chats` (`title` = `NULL`), две в `chat_profile`.
5. Вернуться на профиль и нажать ещё раз — открылся тот же `/chats/N`, новых строк нет.
6. Войти вторым пользователем, открыть профиль первого, нажать «Написать» — тот же чат: поиск симметричен.
7. Открыть профиль третьего человека и нажать «Написать» — новый чат с другим номером.
8. Войти третьим пользователем и открыть `/chats/N` из шага 3 руками — 403.
9. DevTools → Network: `POST` с ответом `302`, затем `GET /chats/N` (раздел 8).
10. `php artisan test --filter=ClientChatTest` — шесть тестов зелёные.
11. `php artisan test` — весь набор зелёный (менялся `ProfileResource`, его проверяют тесты подписок).
12. `vendor/bin/pint --dirty` — правок стиля нет.

## 14. Грабли

- **`relation "chats" does not exist` при `migrate`** — миграция `chat_profile` или `messages` оказалась старше `chats`: команды запускались не в том порядке. Переименуйте файл так, чтобы дата `chats` была раньше, и запустите `migrate` снова.
- **`relation "chat_profiles" does not exist`** (или `profile_chat`) — таблица названа не по конвенции. Либо имя таблицы `chat_profile`, либо вторым аргументом в **обеих** связях: `belongsToMany(Profile::class, 'имя_таблицы')`.
- **`created_at` в `chat_profile` пустой** — в связи забыт `->withTimestamps()`.
- **`Call to undefined method App\Models\Chat::factory()`** — в модели нет `use HasFactory`. `make:model` без `-f` его не добавляет.
- **405 Method Not Allowed при нажатии** — у `<Link>` нет `method="post"`, и запрос ушёл GET-ом на маршрут, который принимает только POST.
- **Нажатие ничего не меняет на экране, а в Network виден ответ с HTML** — кнопка отправлена через axios. Axios прошёл по редиректу сам и принёс страницу в JavaScript; переход должна делать Inertia (`<Link>` или `router.post()`).
- **`Route [clients.chats.show] not defined`** — опечатка в имени маршрута в `redirect()->route()` (так было и на видео урока). Имя должно совпадать с `->name()` в `routes/client.php`.
- **Каждое нажатие создаёт новый чат** — пропущен поиск перед `Chat::create()`.
- **Нажатие у одного профиля открывает чат с другим человеком** — в поиске потерян `whereHas()`, и `$viewer->chats()->first()` возвращает первый попавшийся свой чат. Ловит `test_chat_with_another_profile_is_not_reused`.
- **В базе чат без участников** — `attach()` упал после `Chat::create()`, а транзакции не было.
- **Кнопки «Написать» нет ни на одном профиле** — ключ `can_message` не добавлен в `ProfileResource`: `v-if` получил `undefined`.
- **Чужой чат открывается по прямой ссылке** — нет проверки `abort_unless(...)` в `ChatController::show()`.
- **Заголовок диалога — собственный ник** — в `reject()` сравнение перевёрнуто или стоит `filter()`: из участников нужно убрать смотрящего, а не оставить. Ловит `test_participant_opens_chat_page`.
- **Быстрый двойной клик создал два чата** — два POST-запроса пришли одновременно, и оба не нашли чат. Транзакция от этого не спасает: каждая по отдельности корректна. Для учебного проекта это допустимо; способы исправить — в следующем разделе.

## 15. Что можно сделать лучше

**Список чатов.** Ссылка «Сообщения» в шапке и страница `/chats`: `$profile->chats()->with('profiles')->latest('updated_at')->paginate()`. `ChatResource` для неё уже подходит, если не забыть `with('profiles')`.

**Групповые чаты.** Добавить в поиск `->has('profiles', '=', 2)`, чтобы «Написать» не открывала общий чат на троих. Колонка `title` для групповых чатов уже есть.

**Политика вместо проверки в контроллере.** `ChatPolicy::view()` и `Gate::authorize('view', $chat)` — когда в курсе появятся политики. Правило «видят только участники» тогда переедет в одно место и заработает для будущих маршрутов сообщений.

**Защита от двойного клика.** На клиенте — `router.post()` с флагом отправки и `disabled` на кнопке, как у подписки. На сервере — блокировка строки профиля смотрящего (`lockForUpdate()`) в начале транзакции, до поиска: второй запрос того же пользователя дождётся первого и найдёт уже созданный чат.

**Каскадное удаление.** Удалённый чат должен уносить с собой строки `chat_profile` и сообщения: `->constrained('chats')->cascadeOnDelete()` в обеих таблицах.

## Задание

ЧАТЫ

- `php artisan make:model Chat -m`
- `php artisan make:model Message -m`
- `php artisan make:migration create_chat_profile_table`

1. Сделать переход в чат через кнопку «MESSAGE» в шапке профиля.
2. При нажатии на «MESSAGE» проверять: если есть чат с этим пользователем — просто редирект на существующий чат, если нет — создавать новый.
3. Сообщения пока не создаём.
