# Lesson 34 - Групповые чаты

Цель урока: в шапке появляется пункт «Чаты». Он ведёт на страницу `/chats` со списком чатов пользователя. На этой странице есть кнопка «Добавить участника». Она открывает окно: там можно найти профили по нику, отметить участников, ввести название чата и создать его. После создания пользователь сразу оказывается в новом чате.

По пути разбираются: **чем групповой чат отличается от диалога** и почему «Написать» после этого урока нужно поправить; **сервис `ChatService`**: всё создание чатов в одном месте; **Form Request, который дописывает создателя в участники**, и правила для массива (`members.*`, `distinct`, `min`); **поиск на сервере**: `when()`, `ilike`, `limit()`; **Inertia-форма через `router.post()`**: редирект, ошибки валидации и сохранение состояния страницы; **debounce** для поиска во Vue; **`route().current()` со звёздочкой** в шапке.

Отправная точка — состояние после 33-го урока: чат с одним человеком открывается кнопкой «Написать», сообщения приходят через Reverb.

Главная мысль урока: **групповой чат — это тот же чат, только участников больше и у него есть название**. Схема из 31-го урока («чат + участники») это уже умеет. Миграций нет, а страница чата, отправка сообщений и веб-сокеты работают для групп без единой правки. Новое в уроке — только способ создать такой чат и правило, по которому диалог отличается от группового.

## 1. Карта урока

| Файл | Что меняется |
| --- | --- |
| `routes/client.php` | маршруты `client.chats.index`, `client.chats.store`, `client.profiles.index` |
| `app/Models/Chat.php` | только docblock: правило «диалог или групповой» |
| `app/Services/ChatService.php` | новый: `storeDialog()` и `storeGroup()` |
| `app/Http/Requests/Client/Chat/StoreRequest.php` | новый: название, участники, создатель из сессии |
| `app/Http/Controllers/Client/ChatController.php` | новые `index()` и `store()`, псевдонимы для двух `StoreRequest` |
| `app/Http/Controllers/Client/ProfileController.php` | новый `index()` (поиск), `storeChat()` через сервис |
| `app/Mappers/ChatMapper.php` | новый метод `index()` |
| `app/Http/Resources/Chat/ChatResource.php` | только docblock |
| `resources/js/Layouts/ClientLayout.vue` | пункт «Чаты» в шапке |
| `resources/js/Pages/Client/Chat/Index.vue` | новая страница: список чатов |
| `resources/js/Components/Chat/CreateGroupChat.vue` | новый: кнопка и окно создания чата |
| `tests/Feature/ClientGroupChatTest.php` | новый набор тестов |
| `tests/Feature/ClientChatTest.php` | тест: «Написать» не открывает групповой чат |

Команды для генерации файлов (запускаете вы):

```
php artisan make:class Services/ChatService
php artisan make:request Client/Chat/StoreRequest
php artisan make:test ClientGroupChatTest --phpunit
```

`make:class` создаст заготовку с пустым конструктором `__construct() { // }`. Его удаляем, как у `ChatMapper` в 32-м уроке: у сервиса нет зависимостей, методы статические.

Vue-файлы `Index.vue` и `CreateGroupChat.vue` создаются руками: генератора для них нет.

Миграций в уроке нет. Колонка `chats.title` появилась ещё в 31-м уроке, как раз для групповых чатов.

---

# Часть I. Групповой чат в существующей схеме

## 2. Что уже работает для групп

Всё, что написано в уроках 31–33, опирается на участников чата, а не на «двух собеседников». Поэтому групповой чат получает это бесплатно:

| Что | Где | Почему работает для групп |
| --- | --- | --- |
| Доступ к странице чата | `ChatController::show()` | `hasParticipant()` проверяет, есть ли профиль среди участников, сколько бы их ни было |
| Отправка сообщения | `Message\StoreRequest::authorize()` | тот же `hasParticipant()` |
| Сообщения в реальном времени | `routes/channels.php`, `SendMessageEvent` | канал чата один на всех участников, `toOthers()` исключает только вкладку отправителя |
| Подпись под сообщением | `ItemMessage.vue` | ник автора приходит в каждом сообщении, «моё / чужое» решает `author_id` |
| Заголовок | `ChatResource` | `title ?? ники собеседников`: у группы есть `title`, он и показывается |

Сообщение, отправленное в группу из трёх человек, двое других получат через веб-сокет и увидят слева с ником автора. Писать для этого ничего не нужно.

## 3. Диалог или групповой: правило по `title`

### Проблема

Кнопка «Написать» из 31-го урока ищет «мой чат, в котором участвует он»:

```php
$chat = $viewer->chats()
    ->whereHas('profiles', fn (Builder $query) => $query->whereKey($profile->id))
    ->first();
```

Пока все чаты были диалогами, этого хватало. С групповыми чатами поиск начинает ошибаться:

```
Чат 5 «Работа»: я, Иван, Пётр

Я открываю профиль Ивана и нажимаю «Написать»
→ поиск: мои чаты, где есть Иван → находит чат 5 «Работа»
→ вместо личной переписки с Иваном открывается общий чат
```

Значит, поиску нужно знать, какой чат — диалог.

### Варианты

| Признак диалога | Условие в поиске | Минус |
| --- | --- | --- |
| **нет названия** | `whereNull('title')` | правило неявное: вид чата зависит от того, заполнено ли поле |
| ровно два участника | `has('profiles', '=', 2)` | групповой чат на двоих не отличить от диалога; группе нужно минимум три человека |
| отдельная колонка `is_group` | `where('is_group', false)` | миграция и ещё одно поле, которое нужно заполнять везде, где создаётся чат |

В проекте выбран первый вариант. Он уже заложен в 31-м уроке: `title` сделан `nullable`, потому что «у диалога двух людей своего названия нет», а `ChatFactory` по умолчанию создаёт диалог с `title = null`. Остаётся закрепить правило с другой стороны: **у группового чата название обязательно** (раздел 6).

Неявное правило нужно записать там, где его будут искать, — в модели. `app/Models/Chat.php`, docblock класса:

```php
/**
 * Чат: переписка, к которой присоединены профили-участники.
 *
 * Чаты бывают двух видов, и различаются они по title:
 * - диалог — два участника, title = NULL (заголовок из ника собеседника
 *   собирает ChatResource);
 * - групповой — title обязателен, его проверяет Client\Chat\StoreRequest.
 *
 * Поиск диалога в ChatService::storeDialog() опирается на это правило.
 *
 * HasLog не подключён, как и у Notification: логировать каждый чат незачем.
 */
class Chat extends Model {
```

В 31-м уроке (раздел 15) для групповых чатов предлагался второй вариант, `has('profiles', '=', 2)`. Условие по `title` проще: не нужен подзапрос-счётчик, и групповой чат можно создать даже на двоих.

---

# Часть II. Сервер

## 4. Маршруты

`routes/client.php`. Список и создание чатов — рядом с `client.chats.show`:

```php
    // Список чатов текущего пользователя. Адрес без id — как у profiles/personal:
    // чьи чаты показывать, сервер знает из сессии.
    Route::get('chats', [ChatController::class, 'index'])
        ->name('client.chats.index');

    // Создание группового чата. Тот же адрес, другой глагол — стандартная
    // пара index/store: GET читает список, POST добавляет в него запись.
    //
    // Диалоги по-прежнему создаёт client.profiles.chats.store: у диалога
    // есть «с кем», и этот профиль живёт в адресе.
    Route::post('chats', [ChatController::class, 'store'])
        ->name('client.chats.store');
```

Поиск профилей — рядом с остальными маршрутами `profiles`:

```php
    // Поиск профилей для окна «Добавить участника». Отдаёт JSON для axios,
    // а не страницу — как client.profiles.notifications.index.
    //
    // Параметр поиска приходит в строке запроса: /profiles?search=ivan.
    // С profiles/{profile} адрес не пересекается: там после profiles/ есть сегмент.
    Route::get('profiles', [ProfileController::class, 'index'])
        ->name('client.profiles.index');
```

## 5. `ChatService`: всё создание чатов в одном месте

Сейчас диалог создаётся прямо в `ProfileController::storeChat()`, а групповому чату понадобится свой код. Оба случая устроены одинаково: вставить строку в `chats`, добавить участников в `chat_profile`, обернуть это в транзакцию. Поэтому создание чатов переезжает в сервис, как создание постов живёт в `PostService`.

`app/Services/ChatService.php`:

```php
<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Создание чатов: диалога двух профилей и группового чата.
 *
 * Как и PostService, сервис ничего не знает про HTTP и текущего пользователя:
 * кто создаёт чат, ему передают снаружи. Проверки «можно ли» (есть ли профиль,
 * не пишет ли человек сам себе) остаются в контроллере и Form Request.
 */
class ChatService {
    /**
     * Диалог двух профилей: найти существующий или создать новый.
     */
    public static function storeDialog(Profile $viewer, Profile $profile): Chat {
        // «Среди МОИХ диалогов — тот, в котором участвует ОН».
        $chat = $viewer->chats()
            // Только диалоги: у группового чата есть название (docblock Chat).
            // Без этой строки «Написать» открыла бы общий чат, где мы оба состоим.
            ->whereNull('title')
            ->whereHas('profiles', fn (Builder $query) => $query->whereKey($profile->id))
            ->first();

        if ($chat !== null) {
            return $chat;
        }

        // Чат и участники — вставки в две таблицы. Транзакция не оставит
        // в базе чат без участников, если вторая вставка упадёт.
        return DB::transaction(function () use ($viewer, $profile): Chat {
            $chat = Chat::create();

            $chat->profiles()->attach([$viewer->id, $profile->id]);

            return $chat;
        });
    }

    /**
     * Групповой чат с названием и участниками.
     *
     * Создатель уже лежит в members: его дописал StoreRequest::prepareForValidation().
     * Сервису не нужно знать, кто сейчас вошёл в систему.
     *
     * @param  array{title: string, members: list<int>}  $data
     */
    public static function storeGroup(array $data): Chat {
        return DB::transaction(function () use ($data): Chat {
            $chat = Chat::create(['title' => $data['title']]);

            // attach(), а не sync(): чат только что создан, участников у него нет,
            // сравнивать не с чем. Повторы в members отсекло правило distinct.
            $chat->profiles()->attach($data['members']);

            return $chat;
        });
    }
}
```

`ProfileController::storeChat()` теперь проверяет права и вызывает сервис:

```php
    /**
     * Кнопка «Написать»: открыть диалог с этим профилем.
     *
     * Диалог уже есть — ведём в него, нет — создаём и ведём в новый.
     * Найти или создать — забота ChatService, здесь только проверки и ответ.
     *
     * Возвращает редирект, а не массив: запрос отправляет Inertia-ссылка,
     * и по редиректу она сама откроет страницу чата.
     */
    public function storeChat(Request $request, Profile $profile): RedirectResponse {
        $viewer = $request->user()->profile;

        // Проверки остаются в контроллере: это ответы HTTP (403),
        // а сервис про HTTP не знает.
        abort_if($viewer === null, 403, 'У пользователя нет профиля.');
        abort_if($viewer->id === $profile->id, 403, 'Нельзя написать самому себе.');

        $chat = ChatService::storeDialog($viewer, $profile);

        return redirect()->route('client.chats.show', $chat);
    }
```

Импорты `ProfileController`: уходят `App\Models\Chat` и `Illuminate\Support\Facades\DB`, добавляется `App\Services\ChatService`. `Builder` остаётся: он нужен методам `personal()` и `show()`.

### Отличия от урока

На уроке сервис выглядит так:

```php
public static function store(Profile $profile): Chat
{
    $members = implode('-', Arr::sort([$profile->id, auth()->user()->profile->id]));
    $chat = Chat::firstOrCreate(['members' => $members]);
    $chat->profiles()->syncWithoutDetaching([$profile, auth()->user()->profile]);
    return $chat;
}

public static function storeGroup(array $data): Chat
{
    $chat = Chat::create(['title' => $data['title']]);
    $chat->profiles()->syncWithoutDetaching($data['members']);
    return $chat;
}
```

- **Диалог ищется через ключ `members`.** Этот вариант подробно разобран в 31-м уроке (раздел 16), и в проекте он не реализован. Сервис переносит тот поиск, что уже работает, и добавляет к нему `whereNull('title')`. На уроке групповой чат диалогу не мешает по другой причине: у группы колонка `members` пустая, и `firstOrCreate(['members' => '1-3'])` её не найдёт.
- **`syncWithoutDetaching()` в `store()`** на уроке нужен: `firstOrCreate()` может вернуть уже существующий чат, и `attach()` добавил бы участников второй раз. Это исправление ошибки «`attach()` при каждом нажатии» из 31-го урока. В `storeGroup()` чат всегда новый, поэтому достаточно `attach()`: один `INSERT` вместо `SELECT` существующих участников и `INSERT`.
- **`auth()` внутри сервиса.** У нас оба профиля передаются аргументами, как в `PostService`: сервис одинаково работает из контроллера, теста и консольной команды.
- **`Chat::create($data['title'])`.** В первой версии кода на видео `create()` получает строку вместо массива и падает с `TypeError`. Позже на видео это исправлено на `['title' => $data['title']]`, так и оставляем.
- **Имена `storeDialog()` / `storeGroup()`** вместо `store()` / `storeGroup()`: по имени сразу видно, какой чат создаёт метод.

## 6. `StoreRequest`: название, участники и создатель

`app/Http/Requests/Client/Chat/StoreRequest.php`:

```php
<?php

namespace App\Http\Requests\Client\Chat;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Создать чат может только пользователь с профилем: участники чата — профили.
     *
     * Проверка здесь, а не abort_if() в контроллере: authorize() срабатывает
     * раньше правил. Иначе пользователь без профиля получил бы не 403,
     * а ошибку валидации про null в members.
     */
    public function authorize(): bool {
        return $this->user()->profile !== null;
    }

    /**
     * Из окна приходят title и members — id выбранных профилей.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
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
            'members.*' => ['integer', 'distinct', 'exists:profiles,id'],
        ];
    }

    /**
     * Создатель чата — тоже участник.
     *
     * Из окна приходят только те, кого пригласили: себя в списке поиска нет.
     * Id создателя берём из сессии и дописываем к ним, как author_id
     * у сообщения и комментария.
     *
     * Дописываем, только если members уже массив. Всё остальное оставляем
     * как пришло: не пришёл вовсе — ответит required, пришла строка — array.
     * Приводить значение к массиву здесь нельзя (раздел ниже).
     */
    protected function prepareForValidation(): void {
        $members = $this->input('members');

        if (! is_array($members)) {
            return;
        }

        $this->merge([
            'members' => [...$members, $this->user()?->profile?->id],
        ]);
    }
}
```

### Правила для массива

| Правило | К чему относится | Что проверяет |
| --- | --- | --- |
| `members` → `required` | всё поле | поле пришло и не пустое |
| `members` → `array` | всё поле | пришёл массив, а не строка или число |
| `members` → `min:2` | весь массив | в нём не меньше двух элементов |
| `members.*` → `integer` | каждый элемент | число |
| `members.*` → `distinct` | каждый элемент | не повторяется в этом массиве |
| `members.*` → `exists:profiles,id` | каждый элемент | такой профиль есть в базе |

`*` в имени поля означает «каждый элемент массива». Ошибка по элементу приходит с номером: `members.2`. Из интерфейса такие ошибки получить нельзя: список строит сервер, а повтор отсекается на клиенте. Они защищают от запроса, собранного руками.

`distinct` ловит и попытку добавить себя вручную: если прислать свой id в `members`, после `merge()` он окажется в массиве дважды.

### `prepareForValidation()` не должен чинить данные

Правила проверяют не то, что прислал клиент, а то, что осталось в запросе **после** `prepareForValidation()`. Поэтому метод может подставлять данные с сервера (создателя, `author_id`), но не должен переделывать формат клиентских полей. Иначе он спрячет ошибку от правил.

Так выглядел бы «удобный» вариант с приведением к массиву:

```php
// Так НЕ делаем.
$this->merge([
    'members' => [...(array) $this->input('members', []), $this->user()?->profile?->id],
]);
```

Что происходит с разными значениями `members`:

| Пришло | С `(array)` | С проверкой `is_array()` |
| --- | --- | --- |
| `[7, 9]` | `[7, 9, создатель]` → чат создан | то же самое |
| `[]` | `[создатель]` → ошибка `min:2` | то же самое |
| поле не пришло | `[создатель]` → ошибка `min:2` | `null` → ошибка `required` |
| строка `"7"` | `["7", создатель]` → **чат создан** | `"7"` → ошибка `array` |

С `(array)` строка `"7"` превращается в массив ещё до валидации. Правило `array` видит уже массив и пропускает его, `"7"` проходит `integer` и `exists`, и чат создаётся из запроса неправильного формата. Правило в `rules()` записано, но ни разу не срабатывает, и читатель кода думает, что строку оно отсекает. Проверка `is_array()` оставляет всё, кроме массива, нетронутым, и каждое правило отвечает за свой случай. Эту ошибку ловит `test_group_chat_rejects_members_that_are_not_array` (раздел 15).

### Отличия от урока

На уроке:

```php
public function rules(): array
{
    return [
        'title' => 'required|string',
        'members' => 'required|array',
    ];
}

protected function prepareForValidation()
{
    return $this->merge([
        'members' => array_merge($this->members, auth()->user()->profile),
    ]);
}
```

- **В `members` попадает модель профиля, а не id.** `syncWithoutDetaching()` на уроке это переживёт (связь умеет доставать ключ из модели), но в данных запроса смешаются числа и объект. У нас везде id.
- **`array_merge($this->members, ...)` падает с 500**, если `members` не пришёл или пришёл строкой: `array_merge()` принимает только массивы. У нас создатель дописывается только к массиву, а остальные значения отклоняют `required` и `array`.
- **Нет правил для элементов.** Без `members.*` можно прислать несуществующий id (500 от внешнего ключа) или один id дважды (500 от `unique` в `chat_profile`).
- **Без `min:2`** пустой выбор проходит: `required` видит массив из одного создателя. Получился бы «групповой чат» из одного человека.
- `return` у `$this->merge()` не нужен: метод ничего не возвращает.

## 7. `ChatController`: `index()` и `store()`

В контроллере теперь два разных Form Request с одинаковым именем класса `StoreRequest`: для сообщения и для чата. Два `use` с одним коротким именем PHP не пропустит, поэтому оба получают псевдонимы. Приём уже встречался в проекте: `Illuminate\Http\Response as HttpResponse` в `PostController`.

`app/Http/Controllers/Client/ChatController.php`:

```php
<?php

namespace App\Http\Controllers\Client;

use App\Events\WS\SendMessageEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Chat\StoreRequest as StoreChatRequest;
use App\Http\Requests\Client\Message\StoreRequest as StoreMessageRequest;
use App\Http\Resources\Message\MessageResource;
use App\Mappers\ChatMapper;
use App\Models\Chat;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ChatController extends Controller {
    /**
     * Список чатов текущего пользователя: и диалоги, и групповые.
     */
    public function index(Request $request): Response {
        $profile = $request->user()->profile;

        // Без профиля чатов не бывает. 404 — как у «Моих публикаций»:
        // такой страницы у этого пользователя просто нет.
        abort_if($profile === null, 404);

        return inertia('Client/Chat/Index', ChatMapper::index($profile));
    }

    // show() — без изменений.

    /**
     * Создание группового чата.
     *
     * Создатель уже в members, название проверено: всё это сделал
     * StoreChatRequest. Контроллеру остаётся вызвать сервис и ответить.
     *
     * Редирект, а не JSON: форму отправляет router.post() из Inertia,
     * и по редиректу она сама откроет страницу нового чата (раздел 13).
     */
    public function store(StoreChatRequest $request): RedirectResponse {
        $chat = ChatService::storeGroup($request->validated());

        return redirect()->route('client.chats.show', $chat);
    }

    /**
     * Отправка сообщения в чат.
     *
     * Тело метода без изменений, поменялся только тип запроса:
     * StoreRequest → StoreMessageRequest (псевдоним).
     */
    public function storeMessage(StoreMessageRequest $request, Chat $chat): JsonResponse {
        // ...
    }
}
```

Методы стоят в порядке REST: `index`, `show`, `store`, затем `storeMessage`.

На уроке `index()` собирает ресурс прямо в контроллере:

```php
$chats = ChatResource::collection(auth()->user()->profile->chats)->resolve();
return inertia('Client/Chat/Index', compact('chats'));
```

У этого кода две проблемы. `->chats` без `with('profiles')` даёт N+1: `ChatResource` строит заголовок диалога из участников, и для каждого чата уйдёт отдельный запрос. А у пользователя без профиля `->profile->chats` упадёт с 500. Поэтому проверка профиля стоит в контроллере, а запрос строит маппер.

## 8. `ChatMapper::index()`

`app/Mappers/ChatMapper.php` — новый метод рядом с `show()`:

```php
    /**
     * Пропсы страницы списка чатов.
     *
     * @return array{chats: array<int, array<string, mixed>>}
     */
    public static function index(Profile $profile): array {
        $chats = $profile->chats()
            // Заголовок диалога ChatResource собирает из участников.
            // Без with() на каждый чат ушёл бы отдельный запрос за профилями (N+1).
            ->with('profiles')
            // Новые чаты сверху. По id, а не по дате: id строго возрастает.
            ->latest('id')
            ->get();

        return [
            'chats' => ChatResource::collection($chats)->resolve(),
        ];
    }
```

Импорт: `use App\Models\Profile;`. В docblock класса фраза «Когда появится список чатов, рядом встанет `index()`» устарела:

```php
/**
 * Пропсы страниц чата.
 *
 * Маппер собирает всё, из чего состоит страница, чтобы этим не занимался
 * контроллер. Один публичный метод на одну страницу: index() — Client/Chat/Index,
 * show() — Client/Chat/Show.
 *
 * Методы статические: состояния и зависимостей у маппера нет, как
 * у PostService.
 */
```

Список без пагинации — осознанное упрощение, как и лента сообщений в 32-м уроке.

`ChatResource` не меняется. В его docblock уточняем, откуда берутся участники теперь:

```php
    /**
     * Чат для страницы чата и для списка чатов: заголовок и участники.
     *
     * Ресурс рассчитан на загруженную связь profiles: из участников собирается
     * заголовок, поэтому whenLoaded() здесь не нужен — без участников чат
     * показать нельзя. На странице чата участников уже прочитал
     * Chat::hasParticipant(), в списке их загружает with('profiles')
     * в ChatMapper::index().
     *
     * @return array<string, mixed>
     */
```

## 9. `ProfileController::index()`: поиск профилей

`app/Http/Controllers/Client/ProfileController.php`, новый метод в начале класса:

```php
    /**
     * Поиск профилей для окна «Добавить участника».
     *
     * Возвращает массив, а не Inertia-страницу: список запрашивает axios
     * из модального окна, страница чатов остаётся на месте. Тот же приём,
     * что у indexNotification().
     *
     * @return array<int, array<string, mixed>>
     */
    public function index(Request $request): array {
        $viewer = $request->user()->profile;

        // Без профиля чат не создать, и искать участников незачем.
        abort_if($viewer === null, 403, 'У пользователя нет профиля.');

        // string() возвращает строку-обёртку даже без параметра: ?search= не пришёл —
        // будет пустая строка. trim(): пробелы по краям — не часть ника.
        $search = $request->string('search')->trim()->toString();

        $profiles = Profile::query()
            // Себя в списке нет: создателя в участники дописывает StoreRequest.
            ->whereKeyNot($viewer->id)
            // when(): условие добавляется, только если первый аргумент истинный.
            // Пустой поиск — просто первые профили по алфавиту.
            //
            // ilike — регистронезависимый LIKE в PostgreSQL, как в PostFilter:
            // «anna» найдёт и «Anna».
            ->when($search !== '', fn (Builder $query) => $query->where('nickname', 'ilike', "%{$search}%"))
            ->orderBy('nickname')
            // Окну нужно столько, сколько человек просмотрит глазами.
            // Кого нет в первых двадцати, находят уточнением поиска.
            ->limit(20)
            ->get();

        return ProfileSummaryResource::collection($profiles)->resolve();
    }
```

Импорт: `use App\Http\Resources\Profile\ProfileSummaryResource;`.

**Почему `ProfileSummaryResource`, а не `ProfileResource`, как на уроке.** Окну нужны только id и ник. `ProfileResource` отдаёт дату рождения, город и флаги `can_subscribe` / `can_message`, которые считаются для смотрящего. Визитка из 32-го урока подходит без изменений.

**Почему поиск на сервере.** На уроке `index()` отдаёт `Profile::all()`, а фильтровать предполагается в браузере. Пока профилей десятки, разницы нет. Но в социальной сети это означало бы при каждом открытии окна тянуть в браузер всю таблицу профилей. Запрос с `ilike` и `limit(20)` отдаёт ровно то, что поместится в окно, сколько бы профилей ни было в базе.

---

# Часть III. Vue

## 10. Пункт «Чаты» в шапке

`resources/js/Layouts/ClientLayout.vue`, после «Моих публикаций»:

```vue
                <!--
                    client.chats.* — звёздочка в Ziggy: пункт подсвечен и на списке
                    чатов (client.chats.index), и внутри любого чата (client.chats.show).
                -->
                <Link
                    :href="route('client.chats.index')"
                    class="text-sm font-semibold hover:text-sky-700"
                    :class="route().current('client.chats.*') ? 'text-sky-700' : 'text-gray-900'"
                >
                    Чаты
                </Link>
```

В задании это «кнопка», но по смыслу это переход на страницу. Поэтому здесь `Link`, как у соседних пунктов «Лента» и «Мои публикации». Скрипт раскладки не меняется.

## 11. `Client/Chat/Index.vue`

`resources/js/Pages/Client/Chat/Index.vue`:

```vue
<template>
    <Head title="Чаты" />

    <header class="mb-4 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold text-gray-900">Чаты</h1>

        <!-- Кнопка и модальное окно живут в своём компоненте, как у RepostButton. -->
        <CreateGroupChat />
    </header>

    <p v-if="!chats.length" class="rounded-lg bg-white p-6 text-sm text-gray-500">
        Чатов пока нет.
    </p>

    <ul v-else class="divide-y divide-gray-100 rounded-lg bg-white shadow">
        <li v-for="chat in chats" :key="chat.id">
            <!-- Ссылка на всю строку: по ней легче попасть, чем по короткому заголовку. -->
            <Link
                :href="route('client.chats.show', chat.id)"
                class="flex items-center justify-between gap-4 px-5 py-3 hover:bg-gray-50"
            >
                <!--
                    Заголовок уже собран сервером: у группы — название,
                    у диалога — ник собеседника.
                -->
                <span class="truncate text-sm font-medium text-gray-900">{{ chat.title }}</span>

                <span class="shrink-0 text-xs text-gray-500">
                    Участников: {{ chat.profiles.length }}
                </span>
            </Link>
        </li>
    </ul>
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';
import CreateGroupChat from '@/Components/Chat/CreateGroupChat.vue';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Index',
    layout: ClientLayout,
    components: { Head, Link, CreateGroupChat },
    props: {
        // Ключ chats из ChatMapper::index(). Пустой список приедет как [].
        chats: {
            type: Array,
            required: true,
        },
    },
};
</script>
```

Список после создания чата обновлять не нужно: пользователь сразу уходит на страницу нового чата. Когда он вернётся на `/chats`, страница загрузится заново и новый чат уже будет в списке.

## 12. `CreateGroupChat.vue`

`resources/js/Components/Chat/CreateGroupChat.vue`. Устроен как `RepostButton`: кнопка, `Modal` из Breeze и форма внутри окна. Нового здесь три вещи: поиск с задержкой, выбор нескольких профилей и отправка через `router.post()`.

```vue
<template>
    <!-- Корень один, как у RepostButton: кнопка и модальное окно — два узла. -->
    <div>
        <button
            type="button"
            class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800"
            @click="openModal"
        >
            Добавить участника
        </button>

        <Modal :show="isModalShown" max-width="md" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">Новый групповой чат</h2>

                <input
                    v-model="title"
                    type="text"
                    maxlength="255"
                    placeholder="Название чата"
                    class="mt-4 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                />

                <!--
                    errors.title — строка, а не массив: Inertia отдаёт первое
                    сообщение по каждому полю.
                -->
                <p v-if="errors.title" class="mt-1 text-sm text-red-600">{{ errors.title }}</p>

                <!--
                    Выбранные участники отдельным списком: результаты поиска меняются
                    при каждом вводе, а выбор должен оставаться на виду.
                    Клик по нику убирает человека из выбора.
                -->
                <ul v-if="selectedProfiles.length" class="mt-4 flex flex-wrap gap-2">
                    <li v-for="profile in selectedProfiles" :key="profile.id">
                        <button
                            type="button"
                            class="rounded-full bg-sky-100 px-2.5 py-0.5 text-xs text-sky-800 hover:bg-sky-200"
                            :disabled="isSending"
                            @click="toggleProfile(profile)"
                        >
                            {{ profile.nickname }} ✕
                        </button>
                    </li>
                </ul>

                <input
                    v-model="search"
                    type="search"
                    placeholder="Поиск по нику"
                    class="mt-4 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                />

                <!--
                    «Загружаю…» только при пустом списке: если показывать его на каждый
                    запрос, список будет мигать при вводе каждой буквы.
                -->
                <p v-if="!profiles.length" class="mt-2 text-sm text-gray-500">
                    {{ isLoading ? 'Загружаю…' : 'Никого не нашлось.' }}
                </p>

                <!-- max-h + overflow: двадцать строк не растягивают окно за край экрана. -->
                <ul v-else class="mt-2 max-h-60 divide-y divide-gray-100 overflow-y-auto">
                    <li v-for="profile in profiles" :key="profile.id">
                        <!-- label вокруг чекбокса: отметить можно кликом по нику. -->
                        <label class="flex cursor-pointer items-center gap-3 py-2 text-sm text-gray-700">
                            <!--
                                :checked + @change, а не v-model: выбор хранится
                                объектами профилей (см. selectedProfiles), и переключает
                                его один метод — и здесь, и в списке выбранных.
                            -->
                            <input
                                type="checkbox"
                                class="rounded border-gray-300 text-sky-700 focus:ring-sky-500"
                                :checked="selectedIds.includes(profile.id)"
                                :disabled="isSending"
                                @change="toggleProfile(profile)"
                            />
                            {{ profile.nickname }}
                        </label>
                    </li>
                </ul>

                <p v-if="errors.members" class="mt-1 text-sm text-red-600">{{ errors.members }}</p>

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
                        :disabled="!canSubmit"
                        class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
                        @click="submit"
                    >
                        {{ isSending ? 'Создаю…' : 'Создать чат' }}
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>

<script>
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';

export default {
    name: 'CreateGroupChat',
    components: { Modal },
    data() {
        return {
            isModalShown: false,
            title: '',
            search: '',
            // Результат последнего поиска: меняется при каждом вводе.
            profiles: [],
            // Выбранные профили целиком (id и ник), а не одни id: выбранный человек
            // может пропасть из результатов нового поиска, а его ник всё равно
            // нужно показывать в списке выбранных.
            selectedProfiles: [],
            // Ошибки валидации из onError: { title: '...', members: '...' }.
            errors: {},
            isLoading: false,
            isSending: false,
            // Таймер отложенного поиска (раздел 14).
            searchTimer: null,
        };
    },
    computed: {
        // id выбранных — ровно то, что уйдёт на сервер в members.
        selectedIds() {
            return this.selectedProfiles.map((profile) => profile.id);
        },
        // Проверка на клиенте — удобство, а не правило: правила живут в StoreRequest.
        canSubmit() {
            return !this.isSending && this.title.trim() !== '' && this.selectedProfiles.length > 0;
        },
    },
    watch: {
        /**
         * Поиск с задержкой: запрос уходит, когда пользователь перестал печатать
         * на 300 мс. Каждый новый символ отменяет запланированный запрос
         * и планирует новый.
         */
        search() {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.loadProfiles(), 300);
        },
    },
    /**
     * После создания чата Inertia уходит на его страницу, и компонент исчезает.
     * Запланированный поиск ему уже не нужен.
     */
    beforeUnmount() {
        clearTimeout(this.searchTimer);
    },
    methods: {
        openModal() {
            this.errors = {};
            this.isModalShown = true;
            // Список грузим при каждом открытии: с прошлого раза могли
            // появиться новые профили.
            this.loadProfiles();
        },
        /**
         * Пока запрос в полёте, окно не закрываем — как у RepostButton.
         */
        closeModal() {
            if (this.isSending) {
                return;
            }

            this.isModalShown = false;
        },
        /**
         * axios, а не Inertia: нужен только список для окна, страница
         * и адрес меняться не должны.
         */
        loadProfiles() {
            this.isLoading = true;

            axios
                // params axios сам превратит в ?search=...
                .get(route('client.profiles.index'), { params: { search: this.search } })
                .then((res) => {
                    this.profiles = res.data;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
        /**
         * Отметить профиль или снять отметку.
         *
         * Сравнение по id, а не по объекту: новый поиск приносит новые объекты
         * тех же профилей, и includes(profile) их бы не узнал.
         */
        toggleProfile(profile) {
            if (this.selectedIds.includes(profile.id)) {
                this.selectedProfiles = this.selectedProfiles.filter(
                    (selected) => selected.id !== profile.id,
                );

                return;
            }

            this.selectedProfiles.push(profile);
        },
        /**
         * Создание чата через Inertia: сервер ответит редиректом, и Inertia
         * сама откроет страницу нового чата (раздел 13).
         */
        submit() {
            this.isSending = true;
            this.errors = {};

            router.post(
                route('client.chats.store'),
                {
                    title: this.title,
                    members: this.selectedIds,
                },
                {
                    // Валидация не прошла: окно осталось открытым, показываем ошибки.
                    onError: (errors) => {
                        this.errors = errors;
                    },
                    // И после ошибки, и после успеха.
                    onFinish: () => {
                        this.isSending = false;
                    },
                },
            );
        },
    },
};
</script>
```

Надпись на кнопке — «Добавить участника», как в задании. Окно, которое она открывает, создаёт новый групповой чат из выбранных людей.

## 13. Inertia-форма: `router.post()`, редирект и ошибки

### Три способа отправить данные

В проекте уже есть два способа отправить POST из Vue. Третий появляется в этом уроке:

| | axios (`RepostButton`, сообщения) | `<Link method="post">` («Написать», 31-й урок) | `router.post()` (этот урок) |
| --- | --- | --- | --- |
| Что после ответа | остаёмся на странице, данные обновляем сами | переход на страницу из редиректа | переход на страницу из редиректа |
| Ответ сервера | JSON | редирект | редирект |
| Данные формы | передаём сами | нет | передаём сами |
| Ошибки валидации | 422, `e.response.data.errors.title[0]` | формы нет | `onError(errors)`, `errors.title` — строка |

Создание чата — это форма, после которой нужно **перейти** на другую страницу. Поэтому `router.post()`: это тот же `<Link method="post">` из 31-го урока, только вызванный из кода и с данными формы. Контроллер остаётся таким же, как на уроке: `redirect()->route(...)`.

Можно было отправить axios-ом, вернуть JSON с id чата и затем вызвать `router.visit()`, как в админской форме поста. Это тоже два запроса, но контроллеру пришлось бы отдавать JSON только ради того, чтобы клиент потом сам сделал переход.

### Как проходит отправка

```
«Создать чат» ─► router.post('/chats', { title, members })        заголовок X-Inertia
                │
                ├─ валидация не прошла
                │     ─► 302 → /chats          «назад»: адрес страницы, откуда пришёл запрос
                │     ─► Inertia: GET /chats   ошибки лежат в пропе errors
                │     ─► страница не пересоздаётся: окно открыто, название и выбор на месте
                │     ─► onError({ title: '...', members: '...' })
                │
                └─ чат создан
                      ─► 302 → /chats/12
                      ─► Inertia: GET /chats/12 ─► страница нового чата
```

Три вещи здесь делает Laravel и Inertia без нашего кода:

- **Редирект назад с ошибками.** Если Form Request не прошёл валидацию на обычном (не JSON) запросе, Laravel сам отвечает редиректом на предыдущую страницу и кладёт ошибки в сессию. Запрос от axios получил бы вместо этого 422 с JSON: axios просит JSON, Inertia — страницу.
- **Проп `errors`.** Middleware Inertia (`parent::share()` в `HandleInertiaRequests`) достаёт ошибки из сессии и отдаёт их пропом `errors` на любую страницу. По каждому полю — одна строка, первое сообщение. Колбэк `onError` получает этот же объект.
- **Сохранение состояния.** Для `router.post()`, `put()`, `patch()` и `delete()` Inertia по умолчанию ставит `preserveState: true`. Ответ пришёл на ту же страницу `Client/Chat/Index`, и компонент не пересоздаётся: `data()` окна остаётся прежним. Без этого после ошибки окно закрылось бы, а название и выбранные участники пропали.

Ошибки можно было бы читать прямо из `$page.props.errors.title`, без `onError`. Локальная копия удобнее: её можно очистить при повторном открытии окна, а проп `errors` поменяется только со следующим запросом.

## 14. Поиск: задержка и сервер

**Зачем задержка (debounce).** Без неё каждое нажатие клавиши отправляет запрос: набор «ivan» даёт четыре запроса подряд, и три первых ответа сразу устаревают. `watch` на `search` откладывает запрос на 300 мс, а каждый следующий символ отменяет запланированный (`clearTimeout`) и ставит новый. Запрос уходит один, когда пользователь сделал паузу.

```
i ─ v ─ a ─ n ────(300 мс тишины)───► GET /profiles?search=ivan
```

**Почему `watch`, а не `@input`.** Искать нужно при любом изменении `search`. Сейчас оно меняется только из поля ввода, но `watch` не зависит от того, откуда пришло изменение, и не требует второго обработчика рядом с `v-model`. Тот же приём уже есть в `RepostButton` (`watch: initialCount`).

**Почему выбор хранится отдельно от результатов.** `profiles` — это ответ последнего поиска, он заменяется целиком. Если бы отметки жили в самих результатах, новый поиск стёр бы выбор. Поэтому выбранные профили лежат в `selectedProfiles`, а чекбокс только сверяется с ними: `selectedIds.includes(profile.id)`.

---

# Часть IV. Тесты и проверка

## 15. Тесты

```
php artisan make:test ClientGroupChatTest --phpunit
```

Проверяем: список показывает только свои чаты; групповой чат создаётся с названием и с создателем среди участников; без названия и участников чат не создаётся; `members` не массивом отклоняется, а не приводится к массиву; поиск не зависит от регистра и не показывает смотрящего.

`tests/Feature/ClientGroupChatTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientGroupChatTest extends TestCase {
    use RefreshDatabase;

    public function test_chats_page_lists_only_own_chats(): void {
        $viewer = Profile::factory()->create();

        $ownChat = Chat::factory()->create(['title' => 'Работа']);
        $ownChat->profiles()->attach([$viewer->id, Profile::factory()->create()->id]);

        // Чат, в котором смотрящего нет: в его списке ему не место.
        $foreignChat = Chat::factory()->create(['title' => 'Чужой']);
        $foreignChat->profiles()->attach([
            Profile::factory()->create()->id,
            Profile::factory()->create()->id,
        ]);

        $this->actingAs($viewer->user)
            ->get(route('client.chats.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Chat/Index')
                ->has('chats', 1)
                ->where('chats.0.title', 'Работа')
                ->etc());
    }

    public function test_group_chat_is_created_with_creator_and_members(): void {
        $viewer = Profile::factory()->create();
        $first = Profile::factory()->create();
        $second = Profile::factory()->create();

        // post(), а не postJson(): форму отправляет Inertia и ждёт редирект.
        $response = $this->actingAs($viewer->user)
            ->post(route('client.chats.store'), [
                'title' => 'Работа',
                'members' => [$first->id, $second->id],
            ]);

        $chat = Chat::sole();

        $response->assertRedirect(route('client.chats.show', $chat));

        $this->assertSame('Работа', $chat->title);

        // Создатель в чате, хотя в форме его не было: его дописал StoreRequest.
        // Canonicalizing — порядок участников не важен.
        $this->assertEqualsCanonicalizing(
            [$viewer->id, $first->id, $second->id],
            $chat->profiles->modelKeys(),
        );
    }

    public function test_group_chat_requires_title_and_member(): void {
        $viewer = Profile::factory()->create();

        // from() — страница, с которой пришёл запрос. Туда Laravel вернёт
        // пользователя с ошибками: в браузере это /chats с открытым окном.
        //
        // members пустой, но после prepareForValidation() в нём окажется
        // создатель: required пройдёт, а min:2 — нет. Это и проверяем.
        $this->actingAs($viewer->user)
            ->from(route('client.chats.index'))
            ->post(route('client.chats.store'), [
                'title' => '',
                'members' => [],
            ])
            ->assertRedirect(route('client.chats.index'))
            ->assertSessionHasErrors(['title', 'members']);

        $this->assertDatabaseCount('chats', 0);
    }

    /**
     * members — строка с id настоящего профиля: integer и exists она прошла бы.
     *
     * Если prepareForValidation() приведёт её к массиву через (array), правило
     * array получит уже массив и ничего не заметит, и чат создастся. Тест
     * падает именно в этом случае.
     */
    public function test_group_chat_rejects_members_that_are_not_array(): void {
        $viewer = Profile::factory()->create();
        $member = Profile::factory()->create();

        $this->actingAs($viewer->user)
            ->post(route('client.chats.store'), [
                'title' => 'Работа',
                'members' => (string) $member->id,
            ])
            ->assertSessionHasErrors('members');

        $this->assertDatabaseCount('chats', 0);
    }

    public function test_profile_search_is_case_insensitive_and_excludes_viewer(): void {
        // Ник смотрящего тоже подходит под поиск: так видно, что его
        // исключает whereKeyNot(), а не сам фильтр.
        $viewer = Profile::factory()->create(['nickname' => 'anna_viewer']);
        Profile::factory()->create(['nickname' => 'Anna']);
        Profile::factory()->create(['nickname' => 'boris']);

        // getJson: список запрашивает axios.
        $this->actingAs($viewer->user)
            ->getJson(route('client.profiles.index', ['search' => 'ann']))
            ->assertOk()
            ->assertJsonCount(1)
            // «ann» нашёл «Anna»: ilike не различает регистр, like бы не нашёл.
            ->assertJsonPath('0.nickname', 'Anna');
    }
}
```

`tests/Feature/ClientChatTest.php` — один новый тест. Он ловит главный риск урока: «Написать» открывает групповой чат вместо диалога.

```php
    /**
     * С групповыми чатами «мой чат, где есть он» может оказаться общим.
     * «Написать» должна вести в личный диалог, а не туда.
     */
    public function test_message_button_does_not_reuse_group_chat(): void {
        $viewer = Profile::factory()->create();
        $author = Profile::factory()->create();

        $groupChat = Chat::factory()->create(['title' => 'Работа']);
        $groupChat->profiles()->attach([$viewer->id, $author->id]);

        $response = $this->actingAs($viewer->user)
            ->post(route('client.profiles.chats.store', $author));

        // Появился новый чат без названия — диалог, и редирект ведёт в него.
        $dialog = Chat::query()->whereNull('title')->sole();

        $response->assertRedirect(route('client.chats.show', $dialog));
    }
```

Остальные тесты `ClientChatTest` не меняются: `storeChat()` переехал в сервис, но отвечает так же. Это и проверка рефакторинга: если после переноса они зелёные, поведение «Написать» не сломано.

**Чего здесь нет и почему.**

- **Теста на пользователя без профиля**: `authorize()` устроен так же, как в `Message\StoreRequest`, отдельной логики у чата нет.
- **Тестов на `distinct` и `exists`**: из интерфейса такие данные не отправить, а сами правила — встроенные в Laravel.
- **Тестов Vue**: задержку поиска, выбор участников и открытое после ошибки окно проверяем руками (раздел 16).

## 16. Проверка

1. Запустить команды из раздела 1, заполнить файлы. Должен работать `composer run dev`.
2. **Обновить страницу в браузере целиком** (F5). Маршруты для `route()` во Vue Ziggy отдаёт при полной загрузке страницы, и новых `client.chats.index` и `client.profiles.index` без обновления в браузере не будет.
3. В шапке появился пункт «Чаты». Клик — адрес `/chats`, пункт подсвечен. Открыть любой чат из списка — пункт остался подсвеченным.
4. В списке видны существующие диалоги: заголовок — ник собеседника, «Участников: 2».
5. Нажать «Добавить участника» — открылось окно, в нём до двадцати профилей по алфавиту, своего профиля нет.
6. Набрать в поиске несколько букв. В DevTools → Network один запрос `GET /profiles?search=...` после паузы, а не по запросу на букву. Поиск находит ник независимо от регистра.
7. Отметить двоих, поменять поиск и отметить третьего. Все трое остаются в списке выбранных над поиском. Клик по нику в этом списке снимает отметку, и в результатах поиска галочка тоже снимается.
8. Кнопка «Создать чат» неактивна, пока нет названия или никто не выбран.
9. Ошибки валидации: временно убрать `:disabled="!canSubmit"` у кнопки и нажать её с пустой формой. Под полями появились ошибки, окно открыто, выбор на месте. В Network — `POST /chats` с ответом `302` и следом `GET /chats`. Вернуть `:disabled`.
10. Заполнить название, выбрать участников, создать. Адрес сменился на `/chats/N`, заголовок — название чата, в списке участников есть и вы.
11. В базе: строка в `chats` с этим `title`, в `chat_profile` — по строке на каждого участника, включая создателя.
12. Войти приглашённым участником в другом браузере: чат есть в его списке. Открыть его у двоих участников и написать — сообщение пришло без перезагрузки, с ником автора. Вся переписка из 33-го урока работает без правок.
13. Открыть профиль участника группового чата и нажать «Написать» — открылся личный диалог, а не групповой чат.
14. `php artisan test --filter=ClientGroupChatTest` — пять тестов зелёные.
15. `php artisan test --filter=ClientChatTest` — семь тестов зелёные.
16. `php artisan test` — весь набор зелёный.
17. `vendor/bin/pint --dirty` — правок стиля нет (заготовки `make:class` и `make:request` пишут скобки не в стиле проекта, Pint их поправит).

## 17. Грабли

- **`Cannot use App\Http\Requests\Client\Chat\StoreRequest as StoreRequest because the name is already in use`** — в `ChatController` два `use ...\StoreRequest` без псевдонимов. Нужны `as StoreChatRequest` и `as StoreMessageRequest` (раздел 7).
- **403 при создании чата** — в `authorize()` остался `false` из заготовки.
- **`TypeError: ... create(): Argument #1 ($attributes) must be of type array, string given`** — `Chat::create($data['title'])`, как в первой версии кода на видео. Нужен массив: `['title' => $data['title']]`.
- **«Написать» открывает групповой чат** — в `storeDialog()` нет `whereNull('title')`. Ловит `test_message_button_does_not_reuse_group_chat`.
- **Создатель не попал в свой чат** — в `prepareForValidation()` нет `merge()` с его id. Чат создаётся, но открыть его создатель не может: `show()` отвечает 403. Ловит `test_group_chat_is_created_with_creator_and_members`.
- **Чат из одного создателя** — нет `min:2`: пустой выбор проходит `required`, потому что в `members` уже лежит создатель. Ловит `test_group_chat_requires_title_and_member`.
- **Строка вместо массива в `members` создала чат** — в `prepareForValidation()` значение приведено через `(array)` или `Arr::wrap()`, и правило `array` получает уже готовый массив. Создателя нужно дописывать только к массиву (раздел 6). Ловит `test_group_chat_rejects_members_that_are_not_array`.
- **500 `Unique violation` на `chat_profile`** — один id пришёл в `members` дважды, а `distinct` нет.
- **`All Inertia requests must receive a valid Inertia response, however a plain JSON response was received`** — `store()` возвращает `response()->json(...)`, как `storeMessage()`. Форма отправлена через `router.post()`, ей нужен редирект.
- **Под полем показывается одна буква вместо текста ошибки** — написано `errors.title[0]`, как для 422 от axios. У Inertia ошибка по полю — уже строка, `[0]` берёт её первый символ.
- **После ошибки валидации окно закрылось, выбор пропал** — в `router.post()` передан `preserveState: false`. По умолчанию у `post()` состояние сохраняется, эту опцию писать не нужно.
- **`route 'client.chats.index' is not in the route list`** в консоли браузера — страница не обновлялась после добавления маршрутов. Ziggy отдаёт список маршрутов при полной загрузке страницы, переходы Inertia его не обновляют. Нажать F5.
- **В тестах `Inertia page component file [Client/Chat/Index] does not exist`** — нет Vue-файла или опечатка в пути. В тестах Inertia проверяет, что страница существует на диске.
- **На каждую букву поиска уходит запрос** — нет задержки в `watch` или `clearTimeout()` перед новым `setTimeout()`.
- **Поиск «anna» не находит «Anna»** — в запросе `like` вместо `ilike`. Ловит `test_profile_search_is_case_insensitive_and_excludes_viewer`.
- **В списке для выбора есть я сам** — нет `whereKeyNot($viewer->id)`. Если отметить себя, `distinct` вернёт ошибку по `members.N`, и её не будет видно в окне.
- **Галочка стоит у одного профиля, а в «выбранных» его нет (или наоборот)** — выбор хранится в самих результатах поиска или сравнивается объектами (`includes(profile)`), а не по id. Новый поиск приносит новые объекты тех же профилей.
- **Пункт «Чаты» не подсвечивается внутри чата** — в `route().current()` написано `client.chats.index` вместо `client.chats.*`.
- **Открытие списка чатов делает запрос на каждый чат** — в `ChatMapper::index()` нет `with('profiles')`. Заголовки при этом показываются, поэтому ошибку легко не заметить.
- **У пользователя без профиля `/chats` падает с 500** — `index()` написан как на уроке, `auth()->user()->profile->chats` без проверки профиля.

## 18. Что можно сделать лучше

**Добавлять участников в существующий чат.** Кнопка на странице группового чата и маршрут `POST chats/{chat}/profiles`: тот же поиск, `syncWithoutDetaching()` (здесь он действительно нужен, у чата уже есть участники) и проверка `hasParticipant()` у того, кто добавляет. Окно `CreateGroupChat` для этого можно обобщить, когда появится второе место использования.

**Выйти из чата.** `DELETE chats/{chat}/profiles/me` и `detach()` своего профиля. Для диалога — решить, что происходит с перепиской.

**Новый чат у приглашённых в реальном времени.** Сейчас приглашённый увидит чат в списке только после перезагрузки. Можно отправлять событие в приватный канал каждого участника (по образцу `profiles.{profile}.notifications` из 33-го урока) и дописывать чат в список.

**Сортировка по активности и последнее сообщение.** `protected $touches = ['chat'];` в модели `Message` обновит `chats.updated_at` при каждом сообщении. Тогда список можно сортировать `latest('updated_at')` и показывать под заголовком последнее сообщение.

**Поиск по имени и фамилии.** Добавить в условие `orWhere('first_name', 'ilike', ...)` и `orWhere('second_name', 'ilike', ...)` внутри одной группы `where(fn ...)`, чтобы `whereKeyNot()` продолжал действовать на все варианты.

**Отбрасывать устаревшие ответы поиска.** Если сеть медленная, ответ на старую строку поиска может прийти позже ответа на новую и затереть список. Лечится запоминанием строки, для которой отправлен запрос, и проверкой в `.then()`, или отменой старого запроса через `AbortController`.

**Явный тип чата.** Если появится переименование диалога или групповые чаты без названия, правило «диалог — это `title = NULL`» перестанет работать. Тогда понадобится колонка `type` в `chats` и миграция, которая заполнит её по текущему правилу.

**Политика.** `ChatPolicy` с методами `view()`, `sendMessage()`, `create()`. Правило «создать чат может только пользователь с профилем» переедет из `StoreRequest::authorize()` в `create()`.

## Задание

ГРУППОВЫЕ ЧАТЫ

1. В шапке добавить кнопку «Чаты». Она ведёт на страницу `/chats`, где можно создать групповой чат.
2. На этой странице сделать кнопку «Добавить участника».
3. При нажатии на неё появляется список профилей с поиском: можно выбрать участников, указать имя чата и добавить участников в чат.

**Решения, принятые при подготовке урока:**

- поиск профилей выполняется на сервере (`ilike` по нику, до 20 результатов), а не фильтром по всем профилям в браузере;
- диалог отличается от группового чата по `title`: у диалога он `NULL`, у группового обязателен;
- выбор участников открывается в модальном окне, как у репоста;
- создание и диалога, и группового чата перенесено в `ChatService` (`storeDialog()` и `storeGroup()`).
