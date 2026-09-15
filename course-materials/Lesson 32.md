# Lesson 32 - Сообщения в чатах

Цель урока: на странице чата появляются лента сообщений и форма отправки. Отправленное сообщение сразу встаёт в ленту без перезагрузки. Свои сообщения прижаты вправо и залиты цветом, сообщения собеседника — слева на сером фоне.

По пути разбираются: **отправка через axios с Form Request**, как у комментариев; **правило «только участники»** — один метод модели для страницы и для отправки; **`authorize()` в Form Request** и почему проверка доступа должна идти раньше валидации; **маппер** — класс, который собирает все пропсы страницы, чтобы этим не занимался контроллер; **`computed`** для классов оформления и почему «моё или чужое» решает Vue, а не сервер.

Отправная точка — состояние после 31-го урока: таблицы `chats`, `chat_profile`, `messages` уже есть, страница чата показывает заголовок и участников.

Главная мысль урока: **JSON сообщения один для всех, кто его видит**. Сервер отдаёт факты: текст, `author_id`, дату, ник автора. Ничего, что зависит от смотрящего, в сообщение не попадает — ни флаг «моё», ни поля вроде `can_message` у вложенного автора. «Моё это сообщение или чужое» решает браузер смотрящего. В следующем уроке это станет особенно важно: сообщение, отправленное одним участником, будет приходить другим в реальном времени в том же самом виде.

## 1. Карта урока

| Файл | Что меняется |
| --- | --- |
| `routes/client.php` | маршрут `client.chats.messages.store` |
| `app/Models/Chat.php` | метод `hasParticipant()`: правило «только участники» |
| `app/Http/Requests/Client/Message/StoreRequest.php` | новый: проверка участия, валидация, автор из сессии |
| `app/Http/Resources/Message/MessageResource.php` | новый: сообщение для клиента |
| `app/Http/Resources/Profile/ProfileSummaryResource.php` | новый: визитка автора без полей смотрящего |
| `app/Mappers/ChatMapper.php` | новый: пропсы страницы чата |
| `app/Http/Controllers/Client/ChatController.php` | `show()` через маппер и `hasParticipant()`, новый `storeMessage()` |
| `app/Http/Resources/Chat/ChatResource.php` | только уточнение в docblock |
| `app/Models/Message.php` | `HasFactory` |
| `database/factories/MessageFactory.php` | новая: нужна тестам |
| `resources/js/Pages/Client/Chat/Show.vue` | лента и форма отправки |
| `resources/js/Components/Message/ItemMessage.vue` | новый: одно сообщение, `computed` |
| `tests/Feature/ClientMessageTest.php` | новый набор тестов |

Команды для генерации файлов (запускаете вы):

```
php artisan make:request Client/Message/StoreRequest
php artisan make:resource Message/MessageResource
php artisan make:resource Profile/ProfileSummaryResource
php artisan make:class Mappers/ChatMapper
php artisan make:factory MessageFactory --model=Message
php artisan make:test ClientMessageTest --phpunit
```

Миграций в уроке нет: таблица `messages` создана в 31-м уроке ровно под эту задачу.

`make:class Mappers/ChatMapper` создаст `app/Mappers/ChatMapper.php` с неймспейсом `App\Mappers`. Папки `app/Mappers` в проекте ещё нет, команда заведёт её сама. В заготовке будет пустой конструктор `__construct() { // }` — его удаляем: у маппера нет зависимостей, а пустой конструктор ничего не делает.

`ItemMessage.vue` создаётся руками: генератора для Vue-компонентов нет.

---

# Часть I. Отправка сообщения

## 2. Маршрут

`routes/client.php`, рядом с `client.chats.show`:

```php
    // Отправка сообщения. Адрес вложен в чат: сообщение не существует само
    // по себе, оно всегда «сообщение этого чата» — та же форма, что
    // у posts/{post}/comments.
    Route::post('chats/{chat}/messages', [ChatController::class, 'storeMessage'])
        ->whereNumber('chat')
        ->name('client.chats.messages.store');
```

Имя маршрута и метода — как на уроке. Метод живёт в `ChatController`, как и было задумано в 31-м уроке: всё, что происходит внутри чата, собирается там.

## 3. Правило «только участники»: `Chat::hasParticipant()`

На уроке `storeMessage()` не проверяет, кто отправляет сообщение. Значит, POST на `/chats/5/messages` мог бы отправить любой вошедший пользователь — в чужой чат. Правило «чат доступен только участникам» уже есть в `show()` из 31-го урока, а теперь оно нужно и при отправке.

Проверять его будут два разных места: контроллер (у страницы чата нет Form Request) и `StoreRequest::authorize()` (раздел 4). Чтобы само правило было записано один раз, оно становится методом модели.

`app/Models/Chat.php`:

```php
    /**
     * Участвует ли профиль в чате.
     *
     * Правило «чат доступен только участникам» записано здесь один раз,
     * а вызывают его ChatController::show() и Message\StoreRequest::authorize().
     *
     * $this->profiles — без скобок: при первом обращении Eloquent загрузит
     * участников и запомнит их на модели. В show() та же коллекция потом
     * уйдёт в ChatResource без второго запроса.
     *
     * null — пользователь без профиля: участником чата он быть не может.
     */
    public function hasParticipant(?Profile $profile): bool {
        return $profile !== null && $this->profiles->contains($profile);
    }
```

Модель отвечает на вопрос о своих данных: «есть ли этот профиль среди моих участников». Кто сейчас смотрит, она не знает — профиль ей передают снаружи. Когда в курсе появятся политики, `ChatPolicy` будет вызывать этот же метод.

## 4. `StoreRequest`: проверка участия и данные формы

На уроке запрос называется `StoreMessageRequest` и лежит прямо в `app/Http/Requests`, поля — `body` и `profile_id`. В проекте у клиентских форм своя папка `Client/<Сущность>/StoreRequest` (как у комментария и репоста), а колонки таблицы `messages` названы `content` и `author_id` — по соглашению постов и комментариев. Поэтому ниже эти имена.

`app/Http/Requests/Client/Message/StoreRequest.php`:

```php
<?php

namespace App\Http\Requests\Client\Message;

use App\Models\Chat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Писать в чат могут только его участники.
     *
     * Проверка здесь, а не в контроллере: authorize() срабатывает раньше
     * правил, и посторонний получит 403, не дойдя до валидации.
     *
     * $this->route('chat') — та же модель Chat, что приедет в контроллер:
     * неявная привязка выполняется до Form Request, повторного запроса нет.
     * Несуществующий чат сюда не дойдёт — привязка вернёт 404 раньше.
     */
    public function authorize(): bool {
        /** @var Chat $chat */
        $chat = $this->route('chat');

        return $chat->hasParticipant($this->user()->profile);
    }

    /**
     * Из формы приходит только content. author_id подставляет
     * prepareForValidation(), но правило у него настоящее: без профиля
     * там окажется null, и required вернёт 422, а не 500 от базы.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // Колонка text длину не ограничивает. 2000 — решение продукта,
            // как у комментария: поле без верхней границы — открытая дверь.
            'content' => ['required', 'string', 'max:2000'],
            'author_id' => ['required', 'integer', 'exists:profiles,id'],
        ];
    }

    /**
     * Автор сообщения — профиль из сессии.
     *
     * merge() перетирает author_id, даже если клиент прислал его сам:
     * написать от имени собеседника подменой поля не получится.
     *
     * chat_id здесь нет: его подставит связь $chat->messages()->create().
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'author_id' => $this->user()?->profile?->id,
        ]);
    }
}
```

`rules()` и `prepareForValidation()` устроены как в `Client\Comment\StoreRequest`, только `merge()` короче: у сообщения нет статуса и даты публикации. Главное отличие — `authorize()`: у комментариев там `return true`, а здесь настоящая проверка.

### Порядок: сначала доступ, потом данные

Laravel обрабатывает POST на `/chats/5/messages` в таком порядке:

```
1. Привязка модели Chat          чата с id 5 нет        → 404
2. StoreRequest:
   prepareForValidation()        подставляет author_id
   authorize()                   не участник            → 403
   rules()                       пустой текст           → 422
3. storeMessage()                сообщение создано      → 201
```

Если оставить проверку участия в контроллере, она окажется на шаге 3 — **после** валидации. Тогда посторонний с пустым текстом получит 422, а с заполненным — 403. Его данные будут проверяться правилами (включая запрос `exists` в базу) до того, как выяснится, что писать сюда ему вообще нельзя. Правильный порядок — «можно ли тебе» раньше, чем «правильно ли заполнено». В Form Request для этого и существует `authorize()`: при `false` Laravel сам ответит 403, до правил дело не дойдёт.

`prepareForValidation()` выполняется даже раньше `authorize()`, но для постороннего это безвредно: метод только подставляет `author_id` в запрос, в базу ничего не пишет.

### Компромисс: существование чата видно

Проверка в `authorize()` исправляет порядок, но **не скрывает, есть ли чат**. Несуществующий чат даёт 404 ещё на привязке модели, чужой — 403. Перебирая номера, посторонний может узнать, какие чаты существуют. Содержимое и участников он при этом не увидит.

То же самое уже происходит на странице чата: `show()` с 31-го урока отвечает постороннему 403. Это принятый компромисс: номера чатов идут подряд, и само их количество в учебном проекте не секрет. Если понадобится скрывать и существование, посторонним нужно отвечать 404 в обоих местах: `abort_unless(..., 404)` в `show()`, а в `StoreRequest` переопределить `failedAuthorization()`, чтобы он бросал `NotFoundHttpException`.

## 5. `storeMessage()`

`app/Http/Controllers/Client/ChatController.php` целиком:

```php
<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Message\StoreRequest;
use App\Http\Resources\Message\MessageResource;
use App\Mappers\ChatMapper;
use App\Models\Chat;
use Illuminate\Http\JsonResponse;
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
        // Чат видят только участники. У GET-страницы нет Form Request,
        // поэтому проверка стоит здесь, до сборки пропсов.
        //
        // 403, а не 404 — осознанный компромисс: существование чата
        // посторонний узнать может, содержимое — нет (раздел 4).
        abort_unless($chat->hasParticipant($request->user()->profile), 403);

        // Из чего состоит страница, решает маппер (раздел 8). Контроллер
        // проверяет доступ и передаёт готовый набор пропсов в Inertia.
        return inertia('Client/Chat/Show', ChatMapper::show($chat));
    }

    /**
     * Отправка сообщения в чат.
     *
     * Проверки доступа здесь нет: участие проверил StoreRequest::authorize(),
     * посторонний до этого метода не дойдёт.
     *
     * Возвращает JSON, а не редирект: форма отправляет запрос через axios,
     * страница остаётся на месте, а новое сообщение дописывается в ленту.
     */
    public function storeMessage(StoreRequest $request, Chat $chat): JsonResponse {
        // create() на связи hasMany сам заполнит chat_id,
        // author_id и content пришли из validated().
        $message = $chat->messages()->create($request->validated());

        // Ник автора клиенту нужен сразу: сообщение встанет в ленту
        // без перезагрузки, и подпись под ним должна быть полной.
        $message->load('author');

        // 201 Created и само сообщение в теле — как у комментария.
        // resolve() — плоский объект без обёртки data.
        return response()->json(MessageResource::make($message)->resolve(), 201);
    }
}
```

Что изменилось по сравнению с 31-м уроком.

**Из `show()` ушёл `$chat->load('profiles')`.** Раньше участники загружались явно, а потом проверялись. Теперь `hasParticipant()` сам читает `$this->profiles`, и это тот же один запрос: связь, прочитанная как свойство, загружается один раз и дальше берётся из памяти. Отдельный `load()` перед проверкой ничего бы не сэкономил.

**`abort_unless(...contains(...))` сменился на `hasParticipant()`.** Ответ тот же 403, но правило теперь записано в модели и совпадает с тем, что проверяет `StoreRequest`.

**Импорт `ChatResource` из контроллера ушёл** — ресурсы теперь собирает маппер.

**Почему ответ — 201 с JSON, а на уроке — массив.** На уроке `return MessageResource::make($message)->resolve()` — Laravel превратит массив в JSON с кодом 200. Работает, но в проекте для «создал запись» принят `201 Created` (как в `CommentController::store()`), поэтому ответ собирается через `response()->json(..., 201)`.

## 6. `MessageResource`

`app/Http/Resources/Message/MessageResource.php`:

```php
<?php

namespace App\Http\Resources\Message;

use App\Http\Resources\Profile\ProfileSummaryResource;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource {
    /**
     * Сообщение для ленты чата.
     *
     * JSON сообщения одинаков для всех участников чата: в нём нет ничего,
     * что зависит от текущего пользователя, — ни на верхнем уровне,
     * ни во вложенном авторе.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            // По author_id клиент отличает свои сообщения от чужих (раздел 12).
            // Флага is_mine здесь нет намеренно: он зависел бы от смотрящего.
            'author_id' => $this->author_id,
            'content' => $this->content,
            // created_at Eloquent кастует в Carbon сам. В JSON он уйдёт
            // строкой ISO-8601 в UTC, в местное время её переведёт браузер.
            'created_at' => $this->created_at,
            // whenLoaded: ключ появится, только если связь загружена,
            // и ресурс не спровоцирует лишний запрос на каждое сообщение.
            //
            // ProfileSummaryResource, а не ProfileResource: тот считает
            // can_subscribe и can_message относительно смотрящего.
            'author' => $this->whenLoaded(
                'author',
                fn (Profile $author): array => ProfileSummaryResource::make($author)->resolve(),
            ),
        ];
    }
}
```

### Автор: `ProfileSummaryResource`

У поста и комментария автор вложен через `ProfileResource`. Для сообщения он не подходит: в `ProfileResource` есть `can_subscribe` и `can_message`, а они считаются через `$request->user()`. Один и тот же автор выглядит по-разному для разных людей: отправителю про самого себя придёт `can_message: false`, собеседнику — `true`. Пока каждый получает сообщение своим запросом, это просто лишние поля. В следующем уроке один и тот же JSON начнут рассылать всем участникам, и собеседник получит флаги, посчитанные для отправителя.

Поэтому у автора сообщения своё представление: только то, что одинаково для всех.

`app/Http/Resources/Profile/ProfileSummaryResource.php`:

```php
<?php

namespace App\Http\Resources\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Визитка профиля: кто это, без данных о текущем пользователе.
 *
 * Отдельный ресурс, а не ProfileResource: тот считает can_subscribe
 * и can_message относительно смотрящего, и один профиль выглядит по-разному
 * для разных людей. Здесь только то, что одинаково для всех, — такой JSON
 * можно показать любому участнику чата.
 */
class ProfileSummaryResource extends JsonResource {
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            // Аватар — часть визитки. В ленте сообщений он пока не показывается.
            'img_path' => $this->img_path,
        ];
    }
}
```

Имя называет назначение ресурса, как у `AuthUserResource` и `ProfileWithNotificationsCountResource`: «краткая визитка». `ProfileResource` для постов и комментариев не меняется — там JSON собирается для конкретного зрителя, и флаги ему нужны.

### Почему без аксессоров из урока

На уроке в модели `Message` два аксессора: `author_name` (`$this->profile->name`) и `formatted_date` (`$this->created_at->diffForHumans()`). В проекте оба заменены тем, что уже принято:

- **Автор — вложенный ресурс.** Аксессор `author_name` на каждое сообщение ходил бы в связь, и без `with('author')` это N+1: сто сообщений — сто запросов за профилями. Кроме того, колонки `name` у профиля нет, есть `nickname`.
- **Дата форматируется во Vue** в `computed` (раздел 12), как в `ItemComment`. `diffForHumans()` дал бы строку «5 minutes ago»: язык Carbon берёт из `APP_LOCALE`, а он в проекте `en`. К тому же готовая строка «5 минут назад» устаревает, пока страница открыта, а чат как раз держат открытым долго.

---

# Часть II. Маппер

## 7. Задача: кто собирает пропсы страницы

В 31-м уроке странице чата хватало одного пропса, и контроллер собирал его сам:

```php
return inertia('Client/Chat/Show', [
    'chat' => ChatResource::make($chat)->resolve(),
]);
```

Теперь странице нужны ещё и сообщения. А для них — запрос с сортировкой, `with('author')` против N+1 и коллекция ресурсов с `resolve()`. Контроллер начинает заниматься двумя разными вещами:

- **обработать запрос**: кто пришёл, есть ли у него доступ, какой ответ вернуть;
- **описать страницу**: из каких данных она состоит и в каком виде их получает Vue.

Маппер забирает себе вторую задачу. Это обычный PHP-класс с одним методом на страницу: на вход — модель, на выход — готовый массив пропсов.

**В Laravel нет встроенного понятия «маппер»** и нет команды `make:mapper`. Это договорённость внутри проекта, поэтому класс создаётся универсальной командой `make:class`. Название папки `Mappers` задаёт курс.

## 8. `ChatMapper`

`app/Mappers/ChatMapper.php`:

```php
<?php

namespace App\Mappers;

use App\Http\Resources\Chat\ChatResource;
use App\Http\Resources\Message\MessageResource;
use App\Models\Chat;

/**
 * Пропсы страниц чата.
 *
 * Один публичный метод на одну страницу: show() — страница Client/Chat/Show.
 * Когда появится список чатов, рядом встанет index().
 */
class ChatMapper {
    /**
     * Пропсы страницы чата: сам чат и его сообщения.
     *
     * Ключи массива — это имена пропсов во Vue один к одному: 'messages'
     * здесь — props.messages в Show.vue.
     *
     * @return array{chat: array<string, mixed>, messages: array<int, array<string, mixed>>}
     */
    public static function show(Chat $chat): array {
        // Какие данные нужны странице, знает маппер — значит, и запрос за ними
        // строит он. Контроллеру не нужно помнить, что сообщениям нужен автор.
        //
        // messages() со скобками — запрос, а не загруженная коллекция: так
        // к нему можно добавить with() и сортировку.
        $messages = $chat->messages()
            // Ник автора нужен каждому сообщению. Без with() на сто сообщений
            // ушло бы сто запросов за профилями (N+1).
            ->with('author')
            // Старые сверху, новые снизу, как в любом мессенджере. Без явной
            // сортировки PostgreSQL порядок строк не гарантирует.
            // По id, а не по created_at: у двух сообщений из одной секунды
            // даты совпадут, а id строго возрастает.
            ->oldest('id')
            ->get();

        return [
            // Участники внутри chat — как в 31-м уроке: из них строится заголовок.
            'chat' => ChatResource::make($chat)->resolve(),
            'messages' => MessageResource::collection($messages)->resolve(),
        ];
    }
}
```

На уроке у маппера три ключа: `chat`, `profiles`, `messages`. У нас участники уже лежат внутри `chat` (так устроен `ChatResource` из 31-го урока), и отдельный ключ `profiles` повторял бы их второй раз.

**Почему метод статический.** Состояния и зависимостей у маппера нет: дали модель — получили массив. Это та же форма, что у `PostService::store()`, и вызывается маппер так же — `ChatMapper::show($chat)`.

**Все сообщения без пагинации** — как на уроке, это осознанное упрощение. Для учебного чата на десятки сообщений этого достаточно. Как грузить длинную переписку порциями, сказано в разделе 18.

`ChatResource` не меняется, но устарела одна фраза в его docblock: «контроллер обязан сделать `load('profiles')`». Меняем её на:

```php
    /**
     * Чат для страницы чата: заголовок и участники.
     *
     * Ресурс рассчитан на загруженную связь profiles: из участников собирается
     * заголовок, поэтому whenLoaded() здесь не нужен — без участников чат
     * показать нельзя. К моменту работы ресурса участники уже в памяти:
     * их прочитал Chat::hasParticipant() при проверке доступа в show().
     *
     * @return array<string, mixed>
     */
```

## 9. Маппер, ресурс, сервис: что где

Все три класса «что-то делают с моделями», и их легко перепутать:

| | Resource | Mapper | Service |
| --- | --- | --- | --- |
| Отвечает на вопрос | как выглядит **одна сущность** | из чего состоит **страница** | как **выполнить действие** |
| На входе | модель или коллекция | модель страницы | данные запроса |
| На выходе | массив одной сущности | все пропсы страницы | созданная или изменённая модель |
| Меняет базу | нет | нет, только читает | да, часто в транзакции |
| Пример в проекте | `MessageResource` | `ChatMapper::show()` | `PostService::store()` |

Маппер **пользуется** ресурсами, но не заменяет их. `MessageResource` описывает сообщение один раз, и им пользуются и маппер (лента при открытии), и `storeMessage()` (ответ на отправку). Благодаря этому сообщение из ответа и сообщение из ленты имеют одинаковую форму, и Vue рисует их одним компонентом.

**Когда маппер не нужен.** Если странице достаточно одного ресурса, маппер превращается в обёртку над одной строкой. Переписывать на мапперы остальные страницы проекта (`ProfileController::show()`, ленту) незачем: маппер окупается, когда у страницы несколько пропсов и для них нужны отдельные запросы.

---

# Часть III. Vue

## 10. Где рисовать сообщение

Лента состоит из сообщений, и каждому нужно три вычисления: моё оно или чужое, какие у пузыря классы, как показать дату. Всё это зависит от конкретного сообщения. `computed` аргументов не принимает, поэтому для каждого сообщения нужен свой экземпляр компонента со своими `computed`. Отсюда `ItemMessage.vue`: так же, как `ItemPost` и `ItemComment` отвечают за одну карточку поста и один комментарий.

Форма отправки остаётся прямо на странице, как на уроке. `CommentForm` для неё не подходит: в той зашиты подписи и тексты ошибок комментария. Обобщать компонент ради второго использования рано.

## 11. `Client/Chat/Show.vue`

`resources/js/Pages/Client/Chat/Show.vue` — шапка с участниками остаётся из 31-го урока, вместо заглушки «Сообщений пока нет» появляются лента и форма:

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

    <section class="rounded-lg bg-white p-5 shadow">
        <!--
            Лента. Каждое сообщение — отдельный компонент: ему нужны свои
            computed, а computed не принимает аргументов (раздел 13).
        -->
        <div v-if="chatMessages.length" class="flex flex-col gap-3">
            <ItemMessage
                v-for="message in chatMessages"
                :key="message.id"
                :message="message"
            />
        </div>

        <p v-else class="text-sm text-gray-500">Сообщений пока нет.</p>

        <!--
            form + @submit.prevent: браузер понимает, что это форма, а .prevent
            отменяет штатную перезагрузку страницы — отправляем через axios.
        -->
        <form class="mt-6 border-t border-gray-100 pt-4" @submit.prevent="storeMessage">
            <textarea
                v-model="content"
                rows="2"
                maxlength="2000"
                placeholder="Сообщение…"
                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                :disabled="isSending"
            />

            <!-- Ошибку показываем ту, что вернул сервер: правило живёт в StoreRequest. -->
            <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>

            <div class="mt-2 flex justify-end">
                <button
                    type="submit"
                    :disabled="isSending || !content.trim()"
                    class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
                >
                    {{ isSending ? 'Отправляю…' : 'Отправить' }}
                </button>
            </div>
        </form>
    </section>
</template>

<script>
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import ItemMessage from '@/Components/Message/ItemMessage.vue';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link, ItemMessage },
    props: {
        chat: {
            type: Object,
            required: true,
        },
        // Сообщения на момент открытия страницы — ключ messages из ChatMapper.
        // required: true — маппер отдаёт его всегда, пустой чат приедет с [].
        messages: {
            type: Array,
            required: true,
        },
    },
    data() {
        return {
            // Локальная копия пропса. Новые сообщения дописываются в ленту,
            // а проп принадлежит серверу: писать в него нельзя, поток данных
            // односторонний. Тот же приём, что postData в ItemPost.
            //
            // Имя другое, потому что проп и поле data с одинаковым именем
            // в одном компоненте не уживутся.
            chatMessages: [...this.messages],
            content: '',
            error: '',
            isSending: false,
        };
    },
    methods: {
        storeMessage() {
            this.isSending = true;
            // Старую ошибку убираем до запроса, иначе она провисит до ответа.
            this.error = '';

            axios
                .post(route('client.chats.messages.store', this.chat.id), {
                    content: this.content,
                })
                .then((res) => {
                    // push, а не unshift: лента идёт от старых к новым,
                    // свежее сообщение встаёт в конец.
                    //
                    // В res.data — сообщение ровно в той форме, в какой его
                    // отдаёт маппер: один MessageResource на оба случая.
                    this.chatMessages.push(res.data);
                    // Поле чистим только после успеха: при ошибке текст
                    // должен остаться.
                    this.content = '';
                })
                .catch((e) => {
                    // 422 приходит как { errors: { content: [...] } }. На 403 и 500
                    // такой структуры нет — показываем общий текст.
                    this.error = e.response?.data?.errors?.content?.[0]
                        ?? 'Не удалось отправить сообщение.';
                })
                .finally(() => {
                    this.isSending = false;
                });
        },
    },
};
</script>
```

Отличия от кода урока:

- **Проп `messages`, поле `chatMessages`.** На уроке проп называется `startMessages`, а ключ маппера — `messages`. Inertia передаёт пропсы строго по имени ключа, поэтому при таком расхождении `startMessages` придёт `undefined`, а `required: false` не даст об этом даже предупреждения. Поэтому имя пропса у нас совпадает с ключом маппера, а для локальной копии взято другое имя.
- **`content` — строка**, а не объект `message = {}`: в форме одно поле, и `v-model="content"` читается проще, чем `v-model="message.body"`.
- **Ошибки и блокировка кнопки** — как в `CommentForm`: без них двойной клик отправит два одинаковых сообщения, а 422 пропадёт молча.

`watch` на проп `messages`, как у `ItemPost`, здесь не нужен: страница чата не делает `router.reload()`, и проп после открытия не меняется.

## 12. `ItemMessage.vue`

`resources/js/Components/Message/ItemMessage.vue`:

```vue
<template>
    <!--
        Свои сообщения прижаты вправо, чужие — влево. items-end / items-start
        выравнивают сразу и пузырь, и подпись под ним.
    -->
    <div class="flex flex-col" :class="isMine ? 'items-end' : 'items-start'">
        <!-- whitespace-pre-line сохраняет переносы строк, набранные в textarea. -->
        <p class="max-w-[75%] whitespace-pre-line rounded-2xl px-4 py-2 text-sm" :class="bubbleClass">{{ message.content }}</p>

        <p class="mt-1 px-1 text-xs text-gray-400">
            {{ message.author?.nickname ?? 'Аноним' }} · {{ createdAt }}
        </p>
    </div>
</template>

<script>
export default {
    name: 'ItemMessage',
    props: {
        // Сообщение в том виде, в каком его отдаёт MessageResource.
        message: {
            type: Object,
            required: true,
        },
    },
    computed: {
        /**
         * Моё ли это сообщение.
         *
         * Сравниваем с id ПРОФИЛЯ, а не пользователя: messages.author_id
         * ссылается на profiles. auth.user.id — другое число, и с ним
         * все сообщения оказались бы «чужими».
         *
         * $page — общие пропсы Inertia, доступные в любом компоненте.
         * Профиля может не быть, отсюда ?. — тогда своих сообщений просто нет.
         */
        isMine() {
            return this.message.author_id === this.$page.props.auth.user.profile?.id;
        },
        /**
         * Оформление пузыря.
         *
         * computed зависит от другого computed: когда изменится isMine,
         * Vue пересчитает и его. Классы вынесены сюда, а не записаны
         * тернарником в шаблоне: вариантов оформления два, в каждом по
         * три класса, и в атрибуте они читались бы плохо.
         *
         * rounded-br-sm / rounded-bl-sm — «хвостик» пузыря со стороны автора.
         */
        bubbleClass() {
            return this.isMine
                ? 'rounded-br-sm bg-sky-700 text-white'
                : 'rounded-bl-sm bg-gray-100 text-gray-800';
        },
        /**
         * Дата в местном времени: «15.09.2026, 12:30».
         *
         * С сервера created_at приезжает в UTC, часовой пояс пользователя
         * знает только браузер — так же форматируется дата в ItemComment.
         */
        createdAt() {
            return new Date(this.message.created_at).toLocaleString('ru-RU', {
                dateStyle: 'short',
                timeStyle: 'short',
            });
        },
    },
};
</script>
```

У компонента нет `data()`: он только рисует то, что ему дали. Всё, что в нём вычисляется, выводится из пропа `message` и общих пропсов `$page`.

## 13. `computed`: что важно в этом уроке

`computed` в проекте уже встречался (`publishedAt` в `ItemComment`, `hasMore` в `CommentList`, `unreadCount` в `ClientLayout`). Здесь добавляются три практических момента.

**1. Классы оформления — частый повод для `computed`.** На уроке показан пример из карточки поста:

```js
computed: {
    likedClass() {
        return this.post.is_liked ? '#000' : 'none';
    },
},
```

Пока выбор короткий, тернарник можно оставить прямо в шаблоне (у нас так сделано с `isMine ? 'items-end' : 'items-start'` и с `:fill` в `LikeButton`). Когда вариантов оформления больше или одно и то же условие нужно в нескольких местах, условие переезжает в `computed` и получает имя: `bubbleClass` в шаблоне говорит, *что* это, а не *как* вычисляется.

**2. `computed` может опираться на другой `computed`.** `bubbleClass` читает `isMine`, а `isMine` — проп и `$page`. Vue сам отслеживает эту цепочку: при изменении `message.author_id` пересчитаются оба. Результат кешируется: сколько бы раз шаблон ни обратился к `isMine`, функция выполнится один раз, пока не изменятся её данные.

**3. `computed` не принимает аргументов.** Внутри `v-for` на странице так не получится:

```vue
<!-- Не работает: computed — это значение, а не функция. -->
<div v-for="message in chatMessages" :class="isMine(message) ? '...' : '...'">
```

Есть три рабочих варианта:

| Вариант | Как | Когда подходит |
| --- | --- | --- |
| Компонент на элемент | `ItemMessage` со своими `computed` | у элемента несколько вычислений — **наш случай** |
| Метод | `methods: { isMine(message) {...} }` | одно простое условие; пересчитывается при каждом рендере, кеша нет |
| Флаг с сервера | `'is_mine' => ...` в ресурсе | когда JSON строится для конкретного зрителя (`can_delete` у поста) |

**Почему не флаг с сервера, как `can_delete`.** Карточку поста сервер собирает для одного конкретного зрителя: тот, кто открыл ленту, и получает свой `can_delete`. Сообщение устроено иначе. Ответ на `storeMessage()` получает отправитель, и `is_mine` там был бы `true`. В следующем уроке **тот же самый JSON** начнёт приходить собеседнику в реальном времени, и у него это сообщение тоже оказалось бы «моим». Поэтому сервер отдаёт только факт — `author_id`, — а вывод из него делает браузер того, кто смотрит. Задание урока формулирует это прямо: «сделать это в Vue». По той же причине автор внутри сообщения отдаётся нейтральным `ProfileSummaryResource` без `can_message` и `can_subscribe` (раздел 6).

---

# Часть IV. Тесты и проверка

## 14. Фабрика сообщений

В 31-м уроке фабрику для `Message` отложили до отправки сообщений. Теперь тестам нужны готовые сообщения в чате.

`app/Models/Message.php` — трейт и обновлённый docblock класса:

```php
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Сообщение чата.
 */
class Message extends Model {
    /** @use HasFactory<MessageFactory> */
    use HasFactory;
```

`HasFactory` дописываем руками: фабрика создана отдельной командой, а не флагом `-f` у `make:model`.

`database/factories/MessageFactory.php`:

```php
    public function definition(): array {
        return [
            // Чат и автор по умолчанию создаются свои. В тестах их задают
            // явно: ->for($chat)->for($profile, 'author').
            'chat_id' => Chat::factory(),
            'author_id' => Profile::factory(),
            'content' => fake()->sentence(),
        ];
    }
```

Импорты: `App\Models\Chat`, `App\Models\Profile`. `chat_id` нет в `$fillable` модели, но фабрике это не мешает: при создании записей она отключает защиту массового заполнения.

## 15. Тесты

`tests/Feature/ClientMessageTest.php`. Проверяем: участник отправляет сообщение, и в авторе нет полей смотрящего; автор берётся из сессии; посторонний получает 403 раньше валидации; пустое сообщение не проходит; на странице чата приезжают сообщения именно этого чата.

```php
<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientMessageTest extends TestCase {
    use RefreshDatabase;

    public function test_participant_sends_message(): void {
        $viewer = Profile::factory()->create();
        $companion = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $companion->id]);

        // postJson: форма отправляет запрос через axios и ждёт JSON.
        $this->actingAs($viewer->user)
            ->postJson(route('client.chats.messages.store', $chat), [
                'content' => 'Привет!',
            ])
            ->assertCreated()
            ->assertJsonPath('content', 'Привет!')
            // Автор нужен клиенту сразу: иначе под новым сообщением не будет ника.
            ->assertJsonPath('author.nickname', $viewer->nickname)
            // JSON сообщения один для всех участников: флагов, посчитанных
            // для текущего пользователя, в авторе быть не должно. Упадёт,
            // если вместо ProfileSummaryResource вложить ProfileResource.
            ->assertJsonMissingPath('author.can_message');

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'author_id' => $viewer->id,
            'content' => 'Привет!',
        ]);
    }

    public function test_message_author_is_taken_from_session(): void {
        $viewer = Profile::factory()->create();
        $companion = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $companion->id]);

        // Поля author_id в форме нет, но подставить его в запрос руками
        // ничто не мешает.
        $this->actingAs($viewer->user)
            ->postJson(route('client.chats.messages.store', $chat), [
                'content' => 'Это написал не он',
                'author_id' => $companion->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('messages', ['author_id' => $viewer->id]);
        $this->assertDatabaseMissing('messages', ['author_id' => $companion->id]);
    }

    /**
     * Текст намеренно пустой. Если проверка участия стоит в authorize(),
     * посторонний получит 403 до валидации. Если её перенесут в контроллер,
     * валидация успеет раньше, и тест упадёт на 422.
     */
    public function test_stranger_is_forbidden_before_validation(): void {
        $chat = Chat::factory()->create();
        $chat->profiles()->attach([
            Profile::factory()->create()->id,
            Profile::factory()->create()->id,
        ]);

        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.chats.messages.store', $chat), [
                'content' => '',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_empty_message_is_rejected(): void {
        $viewer = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, Profile::factory()->create()->id]);

        $this->actingAs($viewer->user)
            ->postJson(route('client.chats.messages.store', $chat), [
                'content' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    public function test_chat_page_contains_messages_of_this_chat(): void {
        $viewer = Profile::factory()->create();
        $companion = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $companion->id]);

        Message::factory()->for($chat)->for($companion, 'author')->create(['content' => 'Привет!']);
        Message::factory()->for($chat)->for($viewer, 'author')->create(['content' => 'И тебе привет']);

        // Сообщение из другого чата: на этой странице его быть не должно.
        Message::factory()->create();

        $this->actingAs($viewer->user)
            ->get(route('client.chats.show', $chat))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Chat/Show')
                ->has('chat')
                ->has('messages', 2)
                ->where('messages.0.content', 'Привет!')
                // Ник пришёл — значит, маппер загрузил автора.
                ->where('messages.0.author.nickname', $companion->nickname)
                ->etc());
    }
}
```

**Чего здесь нет и почему.** Нет теста на выделение своих сообщений: это чистая логика Vue, а тесты проекта проверяют сервер. Её проверяем глазами (раздел 16, шаги 3 и 5). Нет отдельного теста на порядок сообщений: на маленькой таблице PostgreSQL и без `oldest('id')` вернёт строки в порядке вставки, и такой тест проходил бы при любом коде.

Существующий `ClientChatTest::test_participant_opens_chat_page` менять не нужно: он заканчивается на `->etc()`, и новый проп `messages` ему не мешает.

## 16. Проверка

1. Запустить команды из раздела 1, заполнить файлы. Для Vue должен работать `composer run dev` (или `npm run dev`).
2. Открыть свой чат `/chats/N`: под шапкой «Сообщений пока нет» и форма. Кнопка «Отправить» неактивна, пока поле пустое.
3. Отправить сообщение: оно появилось справа на синем фоне, поле очистилось. В DevTools → Network — `POST /chats/N/messages` с ответом `201` и JSON сообщения. В `author` только `id`, `nickname` и `img_path`, без `can_message`.
4. Перезагрузить страницу: сообщение на месте и выглядит так же — ленту при открытии и ответ на отправку рисует один и тот же компонент.
5. Войти собеседником и открыть тот же чат: это же сообщение теперь слева на сером фоне. Ответить — ответ встал справа.
6. Вернуться в браузер первого пользователя: без перезагрузки ответа не видно, после перезагрузки он слева. Так и должно быть: доставка в реальном времени — тема следующего урока.
7. Отправить текст из нескольких строк — переносы сохранились и в ленте, и после перезагрузки.
8. `php artisan test --filter=ClientMessageTest` — пять тестов зелёные.
9. `php artisan test --filter=ClientChatTest` — зелёные: `show()` переписан на маппер и `hasParticipant()`.
10. `php artisan test` — весь набор зелёный.
11. `vendor/bin/pint --dirty` — правок стиля нет (заготовка `make:class` пишет скобки не в стиле проекта, Pint их поправит).

## 17. Грабли

- **403 на любую отправку, даже участником** — в `StoreRequest::authorize()` остался `false` из заготовки.
- **500 `column "body" of relation "messages" does not exist`** — поля названы как на уроке. У нас колонки `content` и `author_id`: имена в запросе, форме и `$fillable` должны совпадать с миграцией.
- **Посторонний пишет в чужой чат** — в `authorize()` осталось `return true`, как у комментариев, а проверку участия забыли. Ловит `test_stranger_is_forbidden_before_validation`.
- **Посторонний с пустым текстом получает 422, а не 403** — проверка участия стоит в контроллере, а не в `authorize()`, и валидация успела раньше (раздел 4). Ловит тот же тест.
- **`Call to a member function hasParticipant() on null`** в `authorize()` — `$this->route('chat')` ищет параметр по имени из маршрута. Если в `routes/client.php` параметр назван иначе (`{id}`), модели под именем `chat` нет.
- **В `author` сообщения приехали `can_subscribe` и `can_message`** — вложен `ProfileResource` вместо `ProfileSummaryResource`. Сейчас в интерфейсе этого не видно, но в следующем уроке собеседник получит флаги, посчитанные для отправителя. Ловит `assertJsonMissingPath` в `test_participant_sends_message`.
- **Все сообщения «чужие», даже свои** — в `isMine` сравнение с `auth.user.id` вместо `auth.user.profile.id`. `author_id` — это id профиля.
- **Лента пустая, хотя сообщения в базе есть** — проп во Vue назван не так, как ключ маппера (`startMessages` против `messages`). Inertia сопоставляет пропсы строго по имени.
- **`Class "App\Mappers\ChatMapper" not found`** — нет импорта в контроллере или в файле маппера другой неймспейс (`namespace App\Mappers;`).
- **Сообщения идут вразнобой** — в маппере нет `oldest('id')`.
- **Открытие чата со ста сообщениями делает сто запросов** — в маппере нет `with('author')`, и ресурс грузит автора для каждого сообщения отдельно. Ник при этом показывается, поэтому ошибку легко не заметить.
- **Под только что отправленным сообщением «Аноним»** — в `storeMessage()` нет `$message->load('author')`, и `whenLoaded()` не отдал ключ `author`.
- **`Invalid Date` в подписи** — в `MessageResource` нет `created_at`.
- **`isMine is not a function`** — `computed` вызван с аргументом внутри `v-for` (раздел 13).
- **Предупреждение `Attempting to mutate prop "messages"`** — в `.then()` написано `this.messages = [...]`. Дописывать нужно в локальную копию `chatMessages`.
- **Посторонний по разным ответам (404 и 403) видит, какие чаты существуют** — это не ошибка, а принятый компромисс (раздел 4).
- **Собеседник не видит новое сообщение без перезагрузки** — тоже не ошибка, а граница урока.

## 18. Что можно сделать лучше

**Длинная переписка порциями.** Сейчас страница получает все сообщения чата разом. Для живого чата лучше отдавать последние 50 и догружать более ранние по кнопке «Показать раньше» — тем же приёмом, что комментарии в `CommentList`, только вверх.

**Прокрутка к последнему сообщению.** Ограничить ленту по высоте (`max-h-[60vh] overflow-y-auto`), повесить на неё `ref` и прокручивать вниз при открытии и после отправки: `this.$nextTick(() => { el.scrollTop = el.scrollHeight; })`. `$nextTick` нужен, потому что новое сообщение появится в DOM только после перерисовки.

**Отправка по Enter.** `@keydown.enter.exact.prevent="storeMessage"` на textarea: Enter отправляет, Shift+Enter переносит строку.

**Свежесть чата.** `protected $touches = ['chat'];` в модели `Message` — при каждом новом сообщении Eloquent обновит `chats.updated_at`. Это понадобится списку чатов «последние сверху» (31-й урок, раздел 15).

**Политика.** `ChatPolicy` с методами `view()` и `sendMessage()`, внутри которых вызывается `hasParticipant()`. Тогда `show()` станет `Gate::authorize('view', $chat)`, а `StoreRequest::authorize()` — `$this->user()->can('sendMessage', $chat)`. Правило останется в модели, а решение «кому что можно» соберётся в одном классе.

**Скрыть существование чатов.** Отвечать посторонним 404 вместо 403 и на странице, и при отправке (раздел 4). Понадобится, если количество и номера чатов перестанут считаться открытыми данными.

**Общий форматтер дат.** `toLocaleString('ru-RU', ...)` уже живёт в `ItemComment` и `ItemMessage`. Когда появится третье место, стоит вынести его в общую функцию.

## Задание

СООБЩЕНИЯ

1. Сделать сообщения в чатах.
2. Визуально выделить сообщения пользователя и собеседника (сделать это в Vue).

Дополнительные темы: Mapper, computed.

- `php artisan make:class Mappers/ChatMapper`
