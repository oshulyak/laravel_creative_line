# Lesson 35 - Группы и темы

Цель урока: в шапке появляется пункт «Группы». Он ведёт на `/groups` — каталог всех групп: название, описание, число участников и кнопка «Вступить» / «Выйти». Там же кнопка «Создать группу». На странице группы — список тем и форма новой темы, на странице темы — лента сообщений и форма отправки. Смотреть группы, темы и сообщения может любой вошедший пользователь, а создавать темы и писать в них — только участники группы.

По пути разбираются: **pivot-таблица `group_profile` по конвенции** и почему `belongsToMany` берёт имя таблицы из классов моделей, а не из имени метода; **правило «только участники» через `exists()`** и чем оно отличается от `Chat::hasParticipant()`; **`withCount()` и `withExists()` в каталоге** вместо аксессора с `auth()` из урока; **создатель сразу вступает в свою группу** — сервис с транзакцией; **`authorize()` с правилом из модели** для темы и для сообщения; **подсказка интерфейсу и защита на сервере** — два способа спросить «состоит ли»; **лента темы в стиле форума**: отдельный `ItemThemeMessage` вместо пузырей чата.

Отправная точка — состояние после 34-го урока: чаты, групповые чаты и сообщения в реальном времени работают.

Главная мысль урока: **группа — это чат, открытый для чтения**. Схема «сущность + участники» из 31-го урока повторяется почти один в один: `group_profile` устроена как `chat_profile`, тема — как переписка внутри группы, сообщение темы — как сообщение чата. Меняется только правило доступа. Чат видят только участники, а группу видят все, но писать могут только вступившие. Поэтому «Вступить» — не просто кнопка, а то, что даёт право писать.

## 1. Карта урока

| Файл | Что меняется |
| --- | --- |
| `database/migrations/..._create_groups_table.php` | новая: группы |
| `database/migrations/..._create_themes_table.php` | новая: темы групп |
| `database/migrations/..._create_theme_messages_table.php` | новая: сообщения в темах |
| `database/migrations/..._create_group_profile_table.php` | новая: участники групп (pivot) |
| `app/Models/Group.php` | новая модель: `subscribers()`, `themes()`, `hasSubscriber()` |
| `app/Models/Theme.php` | новая модель: `group()`, `author()`, `messages()` |
| `app/Models/ThemeMessage.php` | новая модель: `theme()`, `author()` |
| `app/Models/Profile.php` | связь `groups()` |
| `database/factories/GroupFactory.php` | новая: нужна тестам |
| `database/factories/ThemeFactory.php` | новая: нужна тестам |
| `database/factories/ThemeMessageFactory.php` | новая: нужна тестам |
| `routes/client.php` | маршруты групп и тем |
| `app/Services/GroupService.php` | новый: создание группы вместе с создателем |
| `app/Http/Requests/Client/Group/StoreRequest.php` | новый: название и описание группы |
| `app/Http/Requests/Client/Theme/StoreRequest.php` | новый: тему создаёт только участник |
| `app/Http/Requests/Client/ThemeMessage/StoreRequest.php` | новый: в тему пишет только участник |
| `app/Http/Resources/Group/GroupResource.php` | новый |
| `app/Http/Resources/Theme/ThemeResource.php` | новый |
| `app/Http/Resources/ThemeMessage/ThemeMessageResource.php` | новый |
| `app/Mappers/GroupMapper.php` | новый: `index()`, `show()` |
| `app/Mappers/ThemeMapper.php` | новый: `show()` |
| `app/Http/Controllers/Client/GroupController.php` | новый: каталог, страница, создание, вступление, создание темы |
| `app/Http/Controllers/Client/ThemeController.php` | новый: страница темы, отправка сообщения |
| `resources/js/Layouts/ClientLayout.vue` | пункт «Группы» в шапке |
| `resources/js/Components/Group/SubscribeGroupButton.vue` | новый: «Вступить / Выйти» и число участников |
| `resources/js/Components/Group/CreateGroup.vue` | новый: кнопка и окно создания группы |
| `resources/js/Components/ThemeMessage/ItemThemeMessage.vue` | новый: сообщение темы в стиле форума |
| `resources/js/Pages/Client/Group/Index.vue` | новая страница: каталог групп |
| `resources/js/Pages/Client/Group/Show.vue` | новая страница: группа и её темы |
| `resources/js/Pages/Client/Theme/Show.vue` | новая страница: тема и сообщения |
| `tests/Feature/ClientGroupTest.php` | новый набор тестов |
| `tests/Feature/ClientThemeTest.php` | новый набор тестов |

Команды для генерации файлов (запускаете вы, **первые четыре — именно в этом порядке**, почему — в разделе 3):

```
php artisan make:model Group -m
php artisan make:model Theme -m
php artisan make:model ThemeMessage -m
php artisan make:migration create_group_profile_table
php artisan make:factory GroupFactory --model=Group
php artisan make:factory ThemeFactory --model=Theme
php artisan make:factory ThemeMessageFactory --model=ThemeMessage
php artisan make:class Services/GroupService
php artisan make:request Client/Group/StoreRequest
php artisan make:request Client/Theme/StoreRequest
php artisan make:request Client/ThemeMessage/StoreRequest
php artisan make:resource Group/GroupResource
php artisan make:resource Theme/ThemeResource
php artisan make:resource ThemeMessage/ThemeMessageResource
php artisan make:class Mappers/GroupMapper
php artisan make:class Mappers/ThemeMapper
php artisan make:controller Client/GroupController
php artisan make:controller Client/ThemeController
php artisan make:test ClientGroupTest --phpunit
php artisan make:test ClientThemeTest --phpunit
```

Первые четыре команды — как в задании. Фабрики создаются отдельными командами, поэтому трейт `HasFactory` в модели придётся дописать руками (раздел 4). Если удобнее, модели можно создать с флагом `-mf` — тогда фабрики и `HasFactory` появятся сами, а команды `make:factory` не нужны.

`make:model ThemeMessage -m` сам назовёт миграцию `create_theme_messages_table`: имя таблицы Laravel выводит из класса — snake_case и множественное число.

`make:class` создаёт заготовку с пустым конструктором `__construct() { // }`. У `GroupService`, `GroupMapper` и `ThemeMapper` его удаляем, как у `ChatMapper` и `ChatService`: зависимостей нет, методы статические.

Vue-файлы создаются руками: генератора для них нет.

После того как миграции заполнены:

```
php artisan migrate
```

---

# Часть I. Схема

## 2. Группа — открытый чат

Четыре новые таблицы:

```
profiles ──< group_profile >── groups ──< themes ──< theme_messages
             (участники)                  │            │
                                     author_id     author_id
                                    (профиль)      (профиль)
```

- **`groups`** — само сообщество: название и описание;
- **`group_profile`** — кто в группе состоит (многие ко многим);
- **`themes`** — темы группы: у каждой группа и автор;
- **`theme_messages`** — сообщения темы: у каждого тема и автор.

Сравнение с чатами из уроков 31–34:

| | Чат | Группа |
| --- | --- | --- |
| Участники | `chat_profile` | `group_profile` — та же форма |
| Что внутри | сообщения | темы, в темах — сообщения |
| Кто видит | только участники | все вошедшие |
| Кто пишет | участники | участники |
| Как стать участником | пригласили при создании | нажал «Вступить» сам |

**Почему тема — не чат с колонкой `group_id`.** Схема подошла бы, но правила доступа у них разные. К чату доступ решает `chat_profile`, к теме — членство в группе. В одной модели появились бы две ветки проверок: «если у чата есть группа, смотри в неё, иначе — в участников». Отдельные модели `Theme` и `ThemeMessage`, как в задании, держат каждое правило в своём месте.

## 3. Миграции

Порядок команд важен. `themes` и `group_profile` ссылаются на `groups`, `theme_messages` — на `themes`. Миграции выполняются в порядке имён файлов, а имя начинается с даты и времени создания. Поэтому сначала `Group`, потом `Theme`, потом `ThemeMessage`.

### `groups`

```php
    public function up(): void {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            // Описание необязательно: группе хватает названия.
            // text, а не string: описание может быть длиннее 255 символов.
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }
```

Названия групп не уникальны: две группы «Laravel» — не ошибка, их различает id. Колонки владельца нет — это решение урока: создатель просто становится первым участником (раздел 7).

### `themes`

```php
    public function up(): void {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            // Группа, которой принадлежит тема.
            $table->foreignId('group_id')->index()->constrained('groups');
            // Автор — профиль. Имя author_id, как у posts, comments и messages.
            // Таблица указана явно: из author_id Laravel вывел бы authors.
            $table->foreignId('author_id')->index()->constrained('profiles');
            $table->string('title');
            $table->timestamps();
        });
    }
```

У темы есть автор, хотя на уроке про него не сказано: в проекте у всего, что пишет пользователь, есть `author_id`. Список тем без автора выглядел бы анонимным.

### `theme_messages`

```php
    public function up(): void {
        Schema::create('theme_messages', function (Blueprint $table) {
            $table->id();
            // Тема, которой принадлежит сообщение.
            $table->foreignId('theme_id')->index()->constrained('themes');
            $table->foreignId('author_id')->index()->constrained('profiles');
            $table->text('content');
            $table->timestamps();
        });
    }
```

Набор колонок повторяет `messages` из 31-го урока, только вместо `chat_id` — `theme_id`.

### `group_profile`

```php
    /**
     * Участники групп: многие ко многим между groups и profiles.
     *
     * Имя по конвенции Laravel — обе модели в единственном числе, по алфавиту:
     * Group + Profile → group_profile. Поэтому связи Group::subscribers()
     * и Profile::groups() обходятся без аргументов.
     */
    public function up(): void {
        Schema::create('group_profile', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->index()->constrained('groups');
            $table->foreignId('profile_id')->index()->constrained('profiles');
            $table->timestamps();
            // Профиль состоит в группе один раз. toggle() дубль не создаст,
            // но два одновременных клика из двух вкладок без индекса могли бы
            // записать две строки.
            $table->unique(['group_id', 'profile_id']);
        });
    }
```

Таблица — точная копия `chat_profile`. Каскадного удаления, как и во всём проекте, нет.

## 4. Модели и связи

### `Group`

`app/Models/Group.php`:

```php
<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Группа: сообщество с участниками и темами.
 *
 * Смотреть группу и её темы может любой вошедший пользователь,
 * создавать темы и писать в них — только участники (hasSubscriber()).
 */
class Group extends Model {
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
    ];

    /**
     * Участники группы (многие ко многим через group_profile).
     *
     * Аргументов нет, хотя метод называется subscribers, а не profiles:
     * belongsToMany берёт имя таблицы и колонок из классов моделей
     * (Group + Profile → group_profile, group_id, profile_id), имя метода
     * на них не влияет. Сравните с Profile::subscribers(): там таблица
     * названа не по конвенции, и все имена указаны руками.
     *
     * withTimestamps() — чтобы attach() и toggle() заполняли даты в pivot.
     */
    public function subscribers(): BelongsToMany {
        return $this->belongsToMany(Profile::class)->withTimestamps();
    }

    /**
     * Темы группы (внешний ключ themes.group_id).
     */
    public function themes(): HasMany {
        return $this->hasMany(Theme::class);
    }

    /**
     * Состоит ли профиль в группе.
     *
     * Правило «писать могут только участники» записано здесь один раз,
     * его вызывают Theme\StoreRequest и ThemeMessage\StoreRequest.
     *
     * subscribers() со скобками и exists(): в базу уходит один SELECT EXISTS,
     * участники в память не загружаются. У Chat::hasParticipant() наоборот:
     * участников чата единицы, и странице чата они всё равно нужны.
     * У группы участников могут быть тысячи, а для проверки нужен один ответ.
     *
     * null — пользователь без профиля: участником он быть не может.
     */
    public function hasSubscriber(?Profile $profile): bool {
        return $profile !== null
            && $this->subscribers()->whereKey($profile->id)->exists();
    }
}
```

**Почему `subscribers`, а не `members`.** В задании это «подписка на группы», и на уроке связь называется `subscribers`, а признак — `is_subscribed`. Так же названы подписки у профилей: `subscribers()`, `is_subscribed`, `toggleSubscribe()`. В коде сохраняем язык курса, а в интерфейсе пишем по смыслу задания: «Вступить» и «Выйти».

**Почему `exists()`, а не `contains()`.** Сравните две записи одного правила:

```php
// Chat::hasParticipant(): загрузить всех участников и искать среди них.
$this->profiles->contains($profile);

// Group::hasSubscriber(): спросить базу, есть ли одна строка.
$this->subscribers()->whereKey($profile->id)->exists();
```

Без скобок — коллекция в памяти, со скобками — запрос. Для чата первый вариант выгоден: участники нужны и проверке, и `ChatResource`. Для группы загружать тысячу профилей ради одного `true` незачем.

### `Theme`

`app/Models/Theme.php`:

```php
<?php

namespace App\Models;

use Database\Factories\ThemeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тема группы: заголовок и лента сообщений.
 */
class Theme extends Model {
    /** @use HasFactory<ThemeFactory> */
    use HasFactory;

    /**
     * group_id в списке нет: темы создаются через связь
     * $group->themes()->create([...]), и ключ группы она подставит сама.
     *
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'title',
    ];

    /**
     * Группа, которой принадлежит тема.
     */
    public function group(): BelongsTo {
        return $this->belongsTo(Group::class);
    }

    /**
     * Автор темы (внешний ключ themes.author_id).
     */
    public function author(): BelongsTo {
        return $this->belongsTo(Profile::class, 'author_id');
    }

    /**
     * Сообщения темы (внешний ключ theme_messages.theme_id).
     *
     * Метод называется messages, а не themeMessages: внутри темы и так
     * понятно, чьи это сообщения. Колонку theme_id Laravel выведет
     * из имени модели Theme.
     */
    public function messages(): HasMany {
        return $this->hasMany(ThemeMessage::class);
    }
}
```

### `ThemeMessage`

`app/Models/ThemeMessage.php`:

```php
<?php

namespace App\Models;

use Database\Factories\ThemeMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сообщение в теме группы.
 *
 * По форме — близнец Message: автор, текст, даты. Отдельная модель нужна,
 * потому что сообщение принадлежит теме, а доступ к нему решает членство в группе.
 */
class ThemeMessage extends Model {
    /** @use HasFactory<ThemeMessageFactory> */
    use HasFactory;

    /**
     * theme_id в списке нет: сообщения создаются через $theme->messages()->create().
     *
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'content',
    ];

    /**
     * Тема, которой принадлежит сообщение.
     */
    public function theme(): BelongsTo {
        return $this->belongsTo(Theme::class);
    }

    /**
     * Автор сообщения (внешний ключ theme_messages.author_id).
     */
    public function author(): BelongsTo {
        return $this->belongsTo(Profile::class, 'author_id');
    }
}
```

### `Profile::groups()`

`app/Models/Profile.php`, рядом с `chats()`:

```php
    /**
     * Группы, в которых состоит профиль (многие ко многим через group_profile).
     *
     * Обратная сторона Group::subscribers(): та же таблица, без аргументов.
     * Через эту связь пользователь вступает в группу и выходит из неё
     * (GroupController::toggleSubscribe()).
     */
    public function groups(): BelongsToMany {
        return $this->belongsToMany(Group::class)->withTimestamps();
    }
```

`HasFactory` во всех трёх моделях дописываем руками: `make:model` без флага `-f` его не добавляет, а без трейта `Group::factory()` в тестах упадёт с «Call to undefined method».

### Отличия от урока

На уроке модель группы выглядит так:

```php
public function subscribers(): BelongsToMany
{
    return $this->belongsToMany(
        Profile::class,
        'subscriber_subscribing',
        'subscribing_id',
        'subscriber_id',
    );
}

public function getIsSubscribedAttribute(): bool
{
    return $this->subscribers->contains('id', auth()->user()->profile->id);
}
```

- **Аргументы связи скопированы с подписок профилей.** Задание заводит для групп отдельную таблицу `group_profile` (последняя команда), и `Profile::groups()` на уроке уже без аргументов. Две стороны одной связи должны смотреть в одну таблицу, поэтому у `Group::subscribers()` аргументов тоже нет. С аргументами с урока запрос ушёл бы в таблицу, которой в проекте нет.
- **Аксессор `is_subscribed` заменён подзапросом в маппере.** Почему — в разделе 10: в каталоге аксессор загружал бы всех участников каждой группы.

## 5. Фабрики

`database/factories/GroupFactory.php`:

```php
/**
 * Участников фабрика не создаёт: в тестах их добавляют через связь,
 * $group->subscribers()->attach(...), — как у ChatFactory.
 *
 * @extends Factory<Group>
 */
class GroupFactory extends Factory {
    public function definition(): array {
        return [
            'title' => fake()->words(2, true),
            'description' => fake()->sentence(),
        ];
    }
}
```

`database/factories/ThemeFactory.php`:

```php
    public function definition(): array {
        return [
            // Группа и автор по умолчанию создаются свои. В тестах их задают
            // явно: ->for($group)->for($profile, 'author').
            'group_id' => Group::factory(),
            'author_id' => Profile::factory(),
            'title' => fake()->sentence(3),
        ];
    }
```

`database/factories/ThemeMessageFactory.php`:

```php
    public function definition(): array {
        return [
            'theme_id' => Theme::factory(),
            'author_id' => Profile::factory(),
            'content' => fake()->sentence(),
        ];
    }
```

Импорты: `App\Models\Group`, `App\Models\Profile`, `App\Models\Theme` — по тому, какие модели упомянуты в `definition()`.

---

# Часть II. Группы

## 6. Маршруты групп

`routes/client.php`, импорт `GroupController` в начало файла и маршруты после чатов:

```php
    // Каталог групп: все группы, а не только мои. Отсюда пользователь
    // находит группу, чтобы вступить в неё.
    Route::get('groups', [GroupController::class, 'index'])
        ->name('client.groups.index');

    // Создание группы. Тот же адрес, другой глагол — пара index/store, как у chats.
    Route::post('groups', [GroupController::class, 'store'])
        ->name('client.groups.store');

    // Страница группы: описание и темы.
    Route::get('groups/{group}', [GroupController::class, 'show'])
        ->whereNumber('group')
        ->name('client.groups.show');

    // Вступить в группу или выйти из неё. Форма та же, что у подписки
    // на профиль: POST на «подписчиков», глагол toggle — в имени маршрута.
    // Один toggle вместо пары store/destroy: состоит ли пользователь
    // в группе прямо сейчас, решает сервер.
    Route::post('groups/{group}/subscribers', [GroupController::class, 'toggleSubscribe'])
        ->whereNumber('group')
        ->name('client.groups.subscribers.toggle');
```

Маршрут создания темы тоже живёт в `groups/{group}/...`, но он появится в части III вместе с остальными маршрутами тем.

## 7. `GroupService`: создатель сразу в группе

Если группу только создать, её создатель окажется снаружи: откроет свою же группу и увидит «Создавать темы могут участники группы». Поэтому вместе с группой в `group_profile` записывается и он. Это две вставки в две таблицы — тот же случай, что создание группового чата в `ChatService::storeGroup()`, и решается он так же: сервис и транзакция.

`app/Services/GroupService.php`:

```php
<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;

/**
 * Создание групп.
 *
 * Как и ChatService, сервис не знает про HTTP и текущего пользователя:
 * создателя ему передают снаружи.
 */
class GroupService {
    /**
     * Группа, в которой создатель сразу состоит.
     *
     * @param  array{title: string, description?: string|null}  $data
     */
    public static function store(array $data, Profile $creator): Group {
        // Если вторая вставка упадёт, транзакция не оставит в базе группу,
        // в которой не состоит даже её создатель.
        return DB::transaction(function () use ($data, $creator): Group {
            $group = Group::create($data);

            // attach(), а не toggle(): группа новая, переключать нечего.
            $group->subscribers()->attach($creator->id);

            return $group;
        });
    }
}
```

Создатель передаётся вторым аргументом, а не дописывается в данные запроса, как в `Chat\StoreRequest`. Там участники — поле формы (`members`), и создатель — ещё один элемент этого массива. У группы в форме участников нет, есть только название и описание.

## 8. `Group\StoreRequest`

`app/Http/Requests/Client/Group/StoreRequest.php`:

```php
<?php

namespace App\Http\Requests\Client\Group;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Создать группу может только пользователь с профилем: создатель
     * становится участником, а участники группы — профили.
     *
     * Без этой проверки GroupService получил бы null вместо профиля
     * и упал с 500.
     */
    public function authorize(): bool {
        return $this->user()->profile !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // max:255 — string() без длины в миграции.
            'title' => ['required', 'string', 'max:255'],
            // Пустое поле из формы придёт как null: пустые строки превращает
            // в null middleware ConvertEmptyStringsToNull. Поэтому nullable.
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

## 9. `GroupResource`

`app/Http/Resources/Group/GroupResource.php`:

```php
<?php

namespace App\Http\Resources\Group;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource {
    /**
     * Группа для каталога, страницы группы и шапки темы.
     *
     * Число участников и признак «я в группе» приходят, только если их
     * посчитал запрос в маппере. В шапке темы счётчик не нужен, и ключа
     * subscribers_count там не будет.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            // whenCounted: ключ появится, только если был withCount()/loadCount().
            'subscribers_count' => $this->whenCounted('subscribers'),
            // whenHas: ключ появится, только если был withExists()/loadExists().
            // Приведение к bool — как у is_subscribed в ProfileResource.
            'is_subscribed' => $this->whenHas('is_subscribed', fn (mixed $value): bool => (bool) $value),
        ];
    }
}
```

## 10. `GroupMapper`: каталог и страница группы

`app/Mappers/GroupMapper.php`:

```php
<?php

namespace App\Mappers;

use App\Http\Resources\Group\GroupResource;
use App\Http\Resources\Theme\ThemeResource;
use App\Models\Group;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Пропсы страниц групп: index() — Client/Group/Index, show() — Client/Group/Show.
 *
 * $viewer — профиль того, кто смотрит. Может быть null: смотреть группы
 * можно и без профиля, тогда is_subscribed просто придёт false.
 */
class GroupMapper {
    /**
     * Каталог: все группы с числом участников и признаком «я в группе».
     *
     * Без пагинации — осознанное упрощение, как у списка чатов.
     *
     * @return array{groups: array<int, array<string, mixed>>}
     */
    public static function index(?Profile $viewer): array {
        $groups = Group::query()
            // Число участников — подзапрос COUNT в том же SELECT.
            ->withCount('subscribers')
            // «Состою ли я в группе» — подзапрос EXISTS, суженный до смотрящего.
            // Тот же приём, что is_liked у постов и is_subscribed у профиля.
            // whereKey(null) у пользователя без профиля не совпадёт ни с одной строкой.
            ->withExists([
                'subscribers as is_subscribed' => fn (Builder $query) => $query->whereKey($viewer?->id),
            ])
            ->latest('id')
            ->get();

        return [
            'groups' => GroupResource::collection($groups)->resolve(),
        ];
    }

    /**
     * Страница группы: сама группа и её темы.
     *
     * @return array{group: array<string, mixed>, themes: array<int, array<string, mixed>>}
     */
    public static function show(Group $group, ?Profile $viewer): array {
        // loadCount() и loadExists(), а не withCount(): модель уже получена
        // привязкой маршрута, достроить её запрос нельзя — догружаем
        // отдельными запросами, как в ProfileController::show().
        $group->loadCount('subscribers');
        $group->loadExists([
            'subscribers as is_subscribed' => fn (Builder $query) => $query->whereKey($viewer?->id),
        ]);

        $themes = $group->themes()
            // Ник автора нужен каждой строке списка. Без with() — N+1.
            ->with('author')
            // Сколько сообщений в теме — подзапросом, сами сообщения не грузим.
            ->withCount('messages')
            // Новые темы сверху.
            ->latest('id')
            ->get();

        return [
            'group' => GroupResource::make($group)->resolve(),
            'themes' => ThemeResource::collection($themes)->resolve(),
        ];
    }
}
```

Весь каталог — **один запрос**, сколько бы групп и участников ни было:

```sql
select groups.*,
    (select count(*) from profiles
        inner join group_profile on profiles.id = group_profile.profile_id
        where groups.id = group_profile.group_id) as subscribers_count,
    exists(select * from profiles
        inner join group_profile on profiles.id = group_profile.profile_id
        where groups.id = group_profile.group_id and profiles.id = :viewer) as is_subscribed
from groups
order by id desc
```

### Отличия от урока

На уроке:

```php
public function index()
{
    $groups = GroupResource::collection(auth()->user()->profile->groups)->resolve();
    return inertia('Client/Group/Index', compact('groups'));
}
```

А `is_subscribed` в ресурсе берётся из аксессора `getIsSubscribedAttribute()` (раздел 4).

- **Только мои группы.** Каталог на уроке показывает группы, где пользователь уже состоит. Найти чужую группу, чтобы вступить, негде. У нас `/groups` — все группы, это решение урока.
- **Аксессор в списке — это N+1, и тяжёлый.** `$this->subscribers` без скобок загружает всех участников группы, чтобы найти среди них одного. Двадцать групп по тысяче участников — двадцать запросов и двадцать тысяч моделей в памяти ради двадцати булевых значений. `withExists()` получает те же двадцать значений одним запросом.
- **`auth()` внутри модели.** Модель начинает зависеть от того, кто вошёл в систему. В тесте или консольной команде без входа, как и у пользователя без профиля, `auth()->user()->profile->id` падает с 500. У нас смотрящий передаётся в маппер аргументом, как в `ChatMapper::index()`.
- **Аксессор в старом стиле** (`getXAttribute`). Если аксессор всё же нужен, в проекте принят `Attribute::make()`, как у `Profile::notificationsCount()`.

## 11. `GroupController`

`app/Http/Controllers/Client/GroupController.php`:

```php
<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Group\StoreRequest as StoreGroupRequest;
use App\Mappers\GroupMapper;
use App\Models\Group;
use App\Services\GroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class GroupController extends Controller {
    /**
     * Каталог групп.
     */
    public function index(Request $request): Response {
        return inertia('Client/Group/Index', GroupMapper::index($request->user()->profile));
    }

    /**
     * Страница группы.
     *
     * Проверки доступа нет — в отличие от ChatController::show(): группу
     * видят все вошедшие. Что можно только участнику, проверяют
     * Form Request темы и сообщения.
     */
    public function show(Request $request, Group $group): Response {
        return inertia('Client/Group/Show', GroupMapper::show($group, $request->user()->profile));
    }

    /**
     * Создание группы: создатель сразу становится участником (GroupService).
     *
     * Редирект, а не JSON: форму отправляет router.post(), и Inertia сама
     * откроет страницу новой группы — как после создания группового чата.
     */
    public function store(StoreGroupRequest $request): RedirectResponse {
        $group = GroupService::store($request->validated(), $request->user()->profile);

        return redirect()->route('client.groups.show', $group);
    }

    /**
     * Вступить в группу или выйти из неё.
     *
     * Массив, а не страница: кнопка отправляет запрос через axios и меняет
     * только свою надпись и счётчик. Близнец PostController::toggleLike().
     *
     * @return array{is_subscribed: bool, subscribers_count: int}
     */
    public function toggleSubscribe(Request $request, Group $group): array {
        $viewer = $request->user()->profile;

        // Участники группы — профили: без профиля вступать некому.
        abort_if($viewer === null, 403, 'У пользователя нет профиля.');

        // toggle() на связи «мои группы»: строка в group_profile есть —
        // удалить, нет — вставить.
        $changes = $viewer->groups()->toggle($group->id);

        return [
            'is_subscribed' => $changes['attached'] !== [],
            // Считаем после переключения: пока страница была открыта,
            // вступить мог кто-то ещё.
            'subscribers_count' => $group->subscribers()->count(),
        ];
    }
}
```

`StoreRequest` импортирован сразу с псевдонимом: в части III в этот же контроллер придёт второй `StoreRequest` — для темы. Приём тот же, что в `ChatController`.

### Отличия от урока

На уроке:

```php
public function store(StoreRequest $request)
{
    $data = $request->validated();
    $group = Group::create($data);
    $group = GroupResource::make($group)->resolve();
    return redirect()->route('client.groups.show', $group->id);
}

public function toggleProfile(Group $group)
{
    auth()->user()->profile->groups()->toggle($group->id);
    return GroupResource::make($group->fresh())->resolve();
}
```

- **`$group->id` после `resolve()`.** `resolve()` возвращает массив, и `$group->id` падает с `Attempt to read property "id" on array`. Ресурс для редиректа не нужен вовсе: в `route()` передаём модель.
- **Имя маршрута** в первой версии кода — `clients.group.show`, позже на видео исправлено на `client.groups.show`. Так и оставляем.
- **Создатель не вступает в группу** — у нас вступает (раздел 7).
- **`toggleProfile()` → `toggleSubscribe()`**: как у профиля, по имени понятно, что переключается подписка.
- **Ответ — `GroupResource` от `$group->fresh()`.** `fresh()` заново читает группу из базы, а `is_subscribed` в ресурсе снова загружает всех участников через аксессор. Кнопке нужны два значения — их и отдаём, как `toggleLike()` отдаёт `is_liked` и `likes_count`.
- **Нет проверки профиля** — у пользователя без профиля 500.

---

# Часть III. Темы и сообщения

## 12. Маршруты тем

`routes/client.php`, импорт `ThemeController` и маршруты после групп:

```php
    // Создание темы. Адрес вложен в группу: тема не бывает сама по себе,
    // как сообщение чата — chats/{chat}/messages. Метод живёт в контроллере
    // родителя, как ChatController::storeMessage().
    //
    // Имя параметра {group} важно: Theme\StoreRequest достаёт группу
    // через $this->route('group').
    Route::post('groups/{group}/themes', [GroupController::class, 'storeTheme'])
        ->whereNumber('group')
        ->name('client.groups.themes.store');

    // Страница темы. Адрес НЕ вложен в группу: у темы свой id, и чтобы найти её,
    // группа не нужна — тот же довод, что у chats/{chat} и comments/{comment}.
    Route::get('themes/{theme}', [ThemeController::class, 'show'])
        ->whereNumber('theme')
        ->name('client.themes.show');

    // Отправка сообщения в тему — близнец client.chats.messages.store.
    Route::post('themes/{theme}/messages', [ThemeController::class, 'storeMessage'])
        ->whereNumber('theme')
        ->name('client.themes.messages.store');
```

## 13. Тема: `Theme\StoreRequest` и `storeTheme()`

`app/Http/Requests/Client/Theme/StoreRequest.php`:

```php
<?php

namespace App\Http\Requests\Client\Theme;

use App\Models\Group;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Создавать темы могут только участники группы.
     *
     * Проверка в authorize(), как в Message\StoreRequest: посторонний
     * получит 403, не дойдя до валидации. Само правило живёт в модели.
     *
     * $this->route('group') — та же модель, что приедет в контроллер:
     * привязка выполняется до Form Request.
     */
    public function authorize(): bool {
        /** @var Group $group */
        $group = $this->route('group');

        return $group->hasSubscriber($this->user()->profile);
    }

    /**
     * Из формы приходит только title, author_id подставляет prepareForValidation().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            'title' => ['required', 'string', 'max:255'],
            'author_id' => ['required', 'integer', 'exists:profiles,id'],
        ];
    }

    /**
     * Автор темы — профиль из сессии. merge() перетирает author_id,
     * даже если клиент прислал его сам.
     *
     * group_id здесь нет: его подставит связь $group->themes()->create().
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'author_id' => $this->user()?->profile?->id,
        ]);
    }
}
```

`GroupController` — новый импорт и метод после `toggleSubscribe()`:

```php
use App\Http\Requests\Client\Theme\StoreRequest as StoreThemeRequest;
```

```php
    /**
     * Создание темы в группе.
     *
     * Участие проверил StoreThemeRequest::authorize(). Редирект — на страницу
     * новой темы: форму отправляет router.post().
     */
    public function storeTheme(StoreThemeRequest $request, Group $group): RedirectResponse {
        // create() на связи hasMany сам заполнит group_id.
        $theme = $group->themes()->create($request->validated());

        return redirect()->route('client.themes.show', $theme);
    }
```

Как Laravel обрабатывает `POST /groups/5/themes`:

```
1. Привязка модели Group      группы нет               → 404
2. StoreThemeRequest:
   authorize()                не участник              → 403
   rules()                    пустой заголовок         → 302 назад, ошибка в errors.title
3. storeTheme()               тема создана             → 302 на /themes/N
```

Ошибка валидации приходит редиректом, а не 422: форму отправляет Inertia, а не axios. Разбор — в 34-м уроке, раздел 13.

## 14. `ThemeResource` и `ThemeMessageResource`

`app/Http/Resources/Theme/ThemeResource.php`:

```php
<?php

namespace App\Http\Resources\Theme;

use App\Http\Resources\Group\GroupResource;
use App\Http\Resources\Profile\ProfileSummaryResource;
use App\Models\Group;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ThemeResource extends JsonResource {
    /**
     * Тема для списка в группе и для шапки страницы темы.
     *
     * Вложенное и посчитанное отдаётся, только если его загрузил маппер:
     * в списке — автор и число сообщений, на странице темы — автор и группа.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'created_at' => $this->created_at,
            'messages_count' => $this->whenCounted('messages'),
            // ProfileSummaryResource: флаги смотрящего автору темы не нужны.
            'author' => $this->whenLoaded(
                'author',
                fn (Profile $author): array => ProfileSummaryResource::make($author)->resolve(),
            ),
            'group' => $this->whenLoaded(
                'group',
                fn (Group $group): array => GroupResource::make($group)->resolve(),
            ),
        ];
    }
}
```

`app/Http/Resources/ThemeMessage/ThemeMessageResource.php`:

```php
<?php

namespace App\Http\Resources\ThemeMessage;

use App\Http\Resources\Profile\ProfileSummaryResource;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ThemeMessageResource extends JsonResource {
    /**
     * Сообщение для ленты темы (ItemThemeMessage.vue).
     *
     * Как и в MessageResource, здесь нет ничего, что зависит от смотрящего:
     * JSON сообщения одинаков для всех, кто открыл тему.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'author_id' => $this->author_id,
            'content' => $this->content,
            // В JSON уйдёт строкой ISO-8601 в UTC, в местное время её переведёт браузер.
            'created_at' => $this->created_at,
            // whenLoaded: ключ появится, только если автор загружен, —
            // ресурс не сделает лишний запрос на каждое сообщение.
            'author' => $this->whenLoaded(
                'author',
                fn (Profile $author): array => ProfileSummaryResource::make($author)->resolve(),
            ),
        ];
    }
}
```

**Почему не взять готовый `MessageResource`.** Технически он бы сработал: у `ThemeMessage` те же поля. Но `Message\MessageResource` в контроллере темы вводит в заблуждение, а формы двух сообщений могут разойтись — например, если у сообщений темы появятся лайки. Отдельный ресурс стоит пары строк.

## 15. `ThemeMapper`

`app/Mappers/ThemeMapper.php`:

```php
<?php

namespace App\Mappers;

use App\Http\Resources\Theme\ThemeResource;
use App\Http\Resources\ThemeMessage\ThemeMessageResource;
use App\Models\Profile;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Builder;

/**
 * Пропсы страницы темы: show() — Client/Theme/Show.
 */
class ThemeMapper {
    /**
     * Тема с автором и группой, сообщения темы.
     *
     * @return array{theme: array<string, mixed>, messages: array<int, array<string, mixed>>}
     */
    public static function show(Theme $theme, ?Profile $viewer): array {
        // Группа нужна шапке (ссылка назад, в группу) и форме: писать может
        // только участник.
        $theme->load(['author', 'group']);

        // is_subscribed — подсказка интерфейсу, показывать ли форму (раздел 17).
        $theme->group->loadExists([
            'subscribers as is_subscribed' => fn (Builder $query) => $query->whereKey($viewer?->id),
        ]);

        // Все сообщения без пагинации — как в ChatMapper::show().
        $messages = $theme->messages()
            ->with('author')
            ->oldest('id')
            ->get();

        return [
            'theme' => ThemeResource::make($theme)->resolve(),
            'messages' => ThemeMessageResource::collection($messages)->resolve(),
        ];
    }
}
```

## 16. Сообщение: `ThemeMessage\StoreRequest` и `ThemeController`

`app/Http/Requests/Client/ThemeMessage/StoreRequest.php`:

```php
<?php

namespace App\Http\Requests\Client\ThemeMessage;

use App\Models\Theme;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Писать в тему могут только участники её группы.
     *
     * То же правило из модели, что у создания темы, только до группы
     * идём через тему: $theme->group — один запрос, hasSubscriber() — ещё один.
     */
    public function authorize(): bool {
        /** @var Theme $theme */
        $theme = $this->route('theme');

        return $theme->group->hasSubscriber($this->user()->profile);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // 2000 — как у сообщений чата и комментариев.
            'content' => ['required', 'string', 'max:2000'],
            'author_id' => ['required', 'integer', 'exists:profiles,id'],
        ];
    }

    /**
     * Автор — профиль из сессии. theme_id подставит $theme->messages()->create().
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'author_id' => $this->user()?->profile?->id,
        ]);
    }
}
```

`app/Http/Controllers/Client/ThemeController.php`:

```php
<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ThemeMessage\StoreRequest;
use App\Http\Resources\ThemeMessage\ThemeMessageResource;
use App\Mappers\ThemeMapper;
use App\Models\Theme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ThemeController extends Controller {
    /**
     * Страница темы. Как и страница группы, открыта всем вошедшим.
     */
    public function show(Request $request, Theme $theme): Response {
        return inertia('Client/Theme/Show', ThemeMapper::show($theme, $request->user()->profile));
    }

    /**
     * Отправка сообщения в тему.
     *
     * Устроено как ChatController::storeMessage(), только без broadcast():
     * другие участники увидят сообщение после перезагрузки страницы.
     */
    public function storeMessage(StoreRequest $request, Theme $theme): JsonResponse {
        // create() на связи hasMany сам заполнит theme_id.
        $message = $theme->messages()->create($request->validated());

        // Ник автора нужен сразу: сообщение встанет в ленту без перезагрузки.
        $message->load('author');

        return response()->json(ThemeMessageResource::make($message)->resolve(), 201);
    }
}
```

`StoreRequest` здесь один, псевдоним не нужен.

## 17. Подсказка и защита: два способа спросить «состоит ли»

Вопрос «состоит ли смотрящий в группе» в уроке задаётся в двух местах, и записан он по-разному:

| | `is_subscribed` | `hasSubscriber()` |
| --- | --- | --- |
| Где | `withExists()` / `loadExists()` в мапперах | метод модели `Group` |
| Для чего | надпись на кнопке, показывать ли форму | `authorize()` в Form Request темы и сообщения |
| Сколько групп за раз | весь каталог одним запросом | одна группа |
| Защищает ли | нет, это подсказка интерфейсу | да: отказ — 403 |

Это то же разделение, что у `can_delete` у поста и `can_subscribe` у профиля: флаг в JSON решает, что **нарисовать**, а сервер при каждом запросе заново решает, что **разрешить**. Скрытая форма от запроса, собранного руками, не защищает. Если пользователь вышел из группы в соседней вкладке, форма в этой вкладке ещё видна, но отправка получит 403.

Записать правило один раз для обоих случаев не получится. `hasSubscriber()` — запрос на одну группу, и в каталоге из двадцати групп он дал бы двадцать запросов. `withExists()` — часть запроса списка, и вызвать его «на одной модели» внутри `authorize()` неудобно.

---

# Часть IV. Vue

## 18. Пункт «Группы» в шапке

`resources/js/Layouts/ClientLayout.vue`, после «Чатов»:

```vue
                <!--
                    Страница темы — client.themes.show, но это тоже раздел групп:
                    подсвечиваем пункт и там.
                -->
                <Link
                    :href="route('client.groups.index')"
                    class="text-sm font-semibold hover:text-sky-700"
                    :class="route().current('client.groups.*') || route().current('client.themes.*') ? 'text-sky-700' : 'text-gray-900'"
                >
                    Группы
                </Link>
```

## 19. `SubscribeGroupButton.vue`

Кнопка «Вступить / Выйти» нужна в двух местах: в каждой карточке каталога и в шапке страницы группы. Поэтому это отдельный компонент, как `LikeButton`. Число участников живёт в нём же: после нажатия меняются и надпись, и число.

`resources/js/Components/Group/SubscribeGroupButton.vue`:

```vue
<template>
    <!-- Корень один: счётчик и кнопка — два узла. -->
    <div class="flex shrink-0 items-center gap-3">
        <span class="text-sm text-gray-500">Участников: {{ count }}</span>

        <button
            type="button"
            :disabled="isPending"
            class="rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50"
            :class="
                isSubscribed
                    ? 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                    : 'border-sky-700 bg-sky-700 text-white hover:bg-sky-800'
            "
            @click="toggle"
        >
            {{ isSubscribed ? 'Выйти' : 'Вступить' }}
        </button>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'SubscribeGroupButton',
    props: {
        // Группа целиком, а не готовый url, как у LikeButton: вступают только
        // в группу, обобщать не для кого. Нужны id, is_subscribed и subscribers_count.
        group: {
            type: Object,
            required: true,
        },
    },
    // Странице группы важно знать, что пользователь вступил: от этого
    // зависит, показывать ли форму новой темы.
    emits: ['toggled'],
    data() {
        return {
            // Локальные копии: кнопка меняет состояние после ответа сервера,
            // а писать в проп нельзя.
            isSubscribed: Boolean(this.group.is_subscribed),
            count: this.group.subscribers_count ?? 0,
            isPending: false,
        };
    },
    methods: {
        toggle() {
            this.isPending = true;

            axios
                .post(route('client.groups.subscribers.toggle', this.group.id))
                .then((res) => {
                    // Оба значения берём из ответа: сервер — единственный источник правды.
                    this.isSubscribed = res.data.is_subscribed;
                    this.count = res.data.subscribers_count;

                    this.$emit('toggled', res.data);
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isPending = false;
                });
        },
    },
};
</script>
```

`watch` на проп, как у `LikeButton`, здесь не нужен: страницы групп не делают частичную перезагрузку, и проп после открытия не меняется.

## 20. `CreateGroup.vue`

Кнопка «Создать группу» и модальное окно. Устроено как `CreateGroupChat` из 34-го урока, только без поиска участников: два поля и `router.post()`.

`resources/js/Components/Group/CreateGroup.vue`:

```vue
<template>
    <div>
        <button
            type="button"
            class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800"
            @click="openModal"
        >
            Создать группу
        </button>

        <Modal :show="isModalShown" max-width="md" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">Новая группа</h2>

                <input
                    v-model="title"
                    type="text"
                    maxlength="255"
                    placeholder="Название группы"
                    class="mt-4 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                />

                <!-- У Inertia ошибка по полю — строка, а не массив. -->
                <p v-if="errors.title" class="mt-1 text-sm text-red-600">{{ errors.title }}</p>

                <textarea
                    v-model="description"
                    rows="3"
                    maxlength="2000"
                    placeholder="Описание (необязательно)"
                    class="mt-4 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                />

                <p v-if="errors.description" class="mt-1 text-sm text-red-600">{{ errors.description }}</p>

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
                        {{ isSending ? 'Создаю…' : 'Создать' }}
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>

<script>
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';

export default {
    name: 'CreateGroup',
    components: { Modal },
    data() {
        return {
            isModalShown: false,
            title: '',
            description: '',
            // Ошибки валидации из onError: { title: '...' }.
            errors: {},
            isSending: false,
        };
    },
    methods: {
        openModal() {
            this.errors = {};
            this.isModalShown = true;
        },
        /**
         * Пока запрос в полёте, окно не закрываем — как у CreateGroupChat.
         */
        closeModal() {
            if (this.isSending) {
                return;
            }

            this.isModalShown = false;
        },
        /**
         * router.post(), а не axios: после создания нужно оказаться на странице
         * новой группы. Сервер ответит редиректом, Inertia откроет её сама.
         * При ошибке валидации окно останется открытым: у post() preserveState
         * по умолчанию true.
         */
        submit() {
            this.isSending = true;
            this.errors = {};

            router.post(
                route('client.groups.store'),
                {
                    title: this.title,
                    description: this.description,
                },
                {
                    onError: (errors) => {
                        this.errors = errors;
                    },
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

## 21. `Client/Group/Index.vue`

`resources/js/Pages/Client/Group/Index.vue`:

```vue
<template>
    <Head title="Группы" />

    <header class="mb-4 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold text-gray-900">Группы</h1>

        <CreateGroup />
    </header>

    <p v-if="!groups.length" class="rounded-lg bg-white p-6 text-sm text-gray-500">
        Групп пока нет. Создайте первую.
    </p>

    <ul v-else class="flex flex-col gap-3">
        <li
            v-for="group in groups"
            :key="group.id"
            class="flex items-start justify-between gap-4 rounded-lg bg-white p-5 shadow"
        >
            <!-- min-w-0: без него внутри flex длинное название не обрежется truncate. -->
            <div class="min-w-0">
                <Link
                    :href="route('client.groups.show', group.id)"
                    class="block truncate font-medium text-gray-900 hover:text-sky-700"
                >
                    {{ group.title }}
                </Link>

                <!-- В каталоге хватит начала описания, целиком оно на странице группы. -->
                <p v-if="group.description" class="mt-1 line-clamp-2 text-sm text-gray-500">
                    {{ group.description }}
                </p>
            </div>

            <!-- Кнопка ведёт своё состояние сама: каталог после вступления перезагружать не нужно. -->
            <SubscribeGroupButton :group="group" />
        </li>
    </ul>
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';
import CreateGroup from '@/Components/Group/CreateGroup.vue';
import SubscribeGroupButton from '@/Components/Group/SubscribeGroupButton.vue';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Index',
    layout: ClientLayout,
    components: { Head, Link, CreateGroup, SubscribeGroupButton },
    props: {
        // Ключ groups из GroupMapper::index().
        groups: {
            type: Array,
            required: true,
        },
    },
};
</script>
```

## 22. `Client/Group/Show.vue`

`resources/js/Pages/Client/Group/Show.vue`:

```vue
<template>
    <Head :title="group.title" />

    <section class="mb-6 flex items-start justify-between gap-4 rounded-lg bg-white p-5 shadow">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold text-gray-900">{{ group.title }}</h1>

            <!-- whitespace-pre-line сохраняет переносы строк из описания. -->
            <p v-if="group.description" class="mt-2 whitespace-pre-line text-sm text-gray-600">
                {{ group.description }}
            </p>
        </div>

        <!-- После вступления форма новой темы появляется без перезагрузки страницы. -->
        <SubscribeGroupButton :group="group" @toggled="isSubscribed = $event.is_subscribed" />
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-medium text-gray-900">Темы</h2>

        <!--
            Форму видят только участники. Это подсказка, а не защита:
            проверку делает Theme\StoreRequest::authorize().
        -->
        <form v-if="isSubscribed" class="mt-4" @submit.prevent="storeTheme">
            <div class="flex gap-2">
                <input
                    v-model="title"
                    type="text"
                    maxlength="255"
                    placeholder="Название новой темы"
                    class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                />

                <button
                    type="submit"
                    :disabled="isSending || !title.trim()"
                    class="shrink-0 rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
                >
                    {{ isSending ? 'Создаю…' : 'Создать тему' }}
                </button>
            </div>

            <p v-if="errors.title" class="mt-1 text-sm text-red-600">{{ errors.title }}</p>
        </form>

        <p v-else class="mt-4 text-sm text-gray-500">
            Создавать темы и писать в них могут участники группы.
        </p>

        <p v-if="!themes.length" class="mt-4 text-sm text-gray-500">Тем пока нет.</p>

        <ul v-else class="mt-4 divide-y divide-gray-100">
            <li v-for="theme in themes" :key="theme.id">
                <Link
                    :href="route('client.themes.show', theme.id)"
                    class="flex items-center justify-between gap-4 rounded-lg px-2 py-3 hover:bg-gray-50"
                >
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium text-gray-900">{{ theme.title }}</span>
                        <span class="block text-xs text-gray-500">{{ theme.author?.nickname ?? 'Аноним' }}</span>
                    </span>

                    <span class="shrink-0 text-xs text-gray-500">Сообщений: {{ theme.messages_count }}</span>
                </Link>
            </li>
        </ul>
    </section>
</template>

<script>
import { Head, Link, router } from '@inertiajs/vue3';
import SubscribeGroupButton from '@/Components/Group/SubscribeGroupButton.vue';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link, SubscribeGroupButton },
    props: {
        group: {
            type: Object,
            required: true,
        },
        // Ключ themes из GroupMapper::show(). Пустой список приедет как [].
        themes: {
            type: Array,
            required: true,
        },
    },
    data() {
        return {
            // Локальная копия: меняется, когда пользователь вступает или выходит.
            // От неё зависит форма, поэтому v-if смотрит сюда, а не в проп.
            isSubscribed: Boolean(this.group.is_subscribed),
            title: '',
            errors: {},
            isSending: false,
        };
    },
    methods: {
        /**
         * router.post(), а не axios: после создания нужно перейти на страницу
         * новой темы. Сервер ответит редиректом, Inertia откроет её сама.
         */
        storeTheme() {
            this.isSending = true;
            this.errors = {};

            router.post(
                route('client.groups.themes.store', this.group.id),
                { title: this.title },
                {
                    onError: (errors) => {
                        this.errors = errors;
                    },
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

**Как страница узнаёт о вступлении.** Кнопка и форма живут в разных местах, но зависят от одного состояния. Кнопка сообщает о нём событием `toggled`, страница хранит своё `isSubscribed` и по нему показывает форму. Запрос `router.reload()` для этого не нужен: всё, что изменилось, уже пришло в ответе на нажатие.

## 23. `Client/Theme/Show.vue` и `ItemThemeMessage`

`resources/js/Pages/Client/Theme/Show.vue`:

```vue
<template>
    <Head :title="theme.title" />

    <section class="mb-6 rounded-lg bg-white p-5 shadow">
        <Link :href="route('client.groups.show', theme.group.id)" class="text-sm text-sky-700 hover:underline">
            ← {{ theme.group.title }}
        </Link>

        <h1 class="mt-2 text-2xl font-semibold text-gray-900">{{ theme.title }}</h1>

        <p class="mt-1 text-sm text-gray-500">Автор: {{ theme.author?.nickname ?? 'Аноним' }}</p>
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <!--
            Лента в стиле форума, а не чата: сообщения одно под другим,
            разделены линией. Номер — положение в ленте: она идёт от старых
            к новым, и новое сообщение дописывается в конец, так что номера
            уже показанных сообщений не сдвигаются.
        -->
        <div v-if="themeMessages.length">
            <ItemThemeMessage
                v-for="(message, index) in themeMessages"
                :key="message.id"
                :message="message"
                :number="index + 1"
            />
        </div>

        <p v-else class="text-sm text-gray-500">Сообщений пока нет.</p>

        <!-- Форма — только участникам. Защита — в ThemeMessage\StoreRequest::authorize(). -->
        <form
            v-if="theme.group.is_subscribed"
            class="mt-6 border-t border-gray-100 pt-4"
            @submit.prevent="storeMessage"
        >
            <textarea
                v-model="content"
                rows="2"
                maxlength="2000"
                placeholder="Сообщение…"
                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                :disabled="isSending"
            />

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

        <p v-else class="mt-6 border-t border-gray-100 pt-4 text-sm text-gray-500">
            Писать в тему могут участники группы.
            <Link :href="route('client.groups.show', theme.group.id)" class="text-sky-700 hover:underline">
                Перейти в группу
            </Link>
        </p>
    </section>
</template>

<script>
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import ItemThemeMessage from '@/Components/ThemeMessage/ItemThemeMessage.vue';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link, ItemThemeMessage },
    props: {
        // Тема с автором и группой — ключ theme из ThemeMapper::show().
        theme: {
            type: Object,
            required: true,
        },
        messages: {
            type: Array,
            required: true,
        },
    },
    data() {
        return {
            // Локальная копия пропса: новые сообщения дописываются в ленту,
            // а в проп писать нельзя. Как chatMessages в Chat/Show.
            themeMessages: [...this.messages],
            content: '',
            error: '',
            isSending: false,
        };
    },
    methods: {
        /**
         * Отправка — как на странице чата, но без веб-сокетов: своё сообщение
         * встаёт в ленту из ответа, чужие видны после перезагрузки.
         */
        storeMessage() {
            this.isSending = true;
            this.error = '';

            axios
                .post(route('client.themes.messages.store', this.theme.id), {
                    content: this.content,
                })
                .then((res) => {
                    this.themeMessages.push(res.data);
                    this.content = '';
                })
                .catch((e) => {
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

`resources/js/Components/ThemeMessage/ItemThemeMessage.vue`:

```vue
<template>
    <!--
        Сообщение форума, а не пузырь чата: все сообщения выровнены одинаково,
        автор и дата — в шапке. Чья это реплика, видно по нику, а не по стороне экрана.

        first:border-t-0 — у первого сообщения нет разделителя сверху:
        над ним и так граница блока.
    -->
    <article class="flex gap-3 border-t border-gray-100 py-4 first:border-t-0 first:pt-0">
        <!-- Заглушка аватара с первой буквой ника: так реплики легче различать глазами. -->
        <div
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sm font-semibold uppercase text-sky-800"
        >
            {{ initial }}
        </div>

        <!-- min-w-0: без него длинное слово растянет flex-строку за край блока. -->
        <div class="min-w-0 flex-1">
            <header class="flex items-baseline gap-2">
                <span class="text-sm font-medium text-gray-900">{{ nickname }}</span>
                <span class="text-xs text-gray-400">{{ createdAt }}</span>

                <!-- Номер сообщения в теме, как на форумах: на него удобно сослаться в ответе. -->
                <span class="ml-auto text-xs text-gray-400">#{{ number }}</span>
            </header>

            <!--
                whitespace-pre-line сохраняет переносы строк из textarea,
                break-words переносит слово, которое не помещается в строку целиком.
            -->
            <p class="mt-1 whitespace-pre-line break-words text-sm text-gray-700">{{ message.content }}</p>
        </div>
    </article>
</template>

<script>
export default {
    name: 'ItemThemeMessage',
    props: {
        // Сообщение в том виде, в каком его отдаёт ThemeMessageResource.
        message: {
            type: Object,
            required: true,
        },
        // Порядковый номер в теме. Считает страница по положению в ленте:
        // в самом сообщении номера нет, это не колонка базы.
        number: {
            type: Number,
            required: true,
        },
    },
    computed: {
        // ?. и ?? — страховка, как в ItemComment: author приходит из whenLoaded().
        nickname() {
            return this.message.author?.nickname ?? 'Аноним';
        },
        initial() {
            return this.nickname.charAt(0);
        },
        /**
         * Дата в местном времени: «15 сентября 2026 г. в 12:30».
         *
         * dateStyle: 'long', как в ItemComment: тема живёт долго, и дата
         * с названием месяца читается лучше, чем 15.09.2026.
         */
        createdAt() {
            return new Date(this.message.created_at).toLocaleString('ru-RU', {
                dateStyle: 'long',
                timeStyle: 'short',
            });
        },
    },
};
</script>
```

### Почему не `ItemMessage` из чата

`ThemeMessageResource` отдаёт те же ключи, что `MessageResource`, поэтому технически `ItemMessage` отрисовал бы и ленту темы. Но у чата и темы разный характер переписки:

| | Чат (`ItemMessage`) | Тема (`ItemThemeMessage`) |
| --- | --- | --- |
| Сколько собеседников | обычно двое-трое | сколько угодно участников группы |
| Главный вопрос при чтении | «я или он?» | «кто и когда написал?» |
| Раскладка | свои справа, чужие слева, пузыри | все одним столбцом, шапка с автором |
| Номер сообщения | не нужен | `#N`: на него ссылаются в ответах |

В чате на двоих сторона экрана сразу отвечает, чья реплика. В теме с десятком авторов половина сообщений «чужие слева», и различать их всё равно приходится по нику. Поэтому оформление ближе к комментариям (`ItemComment`): ник и дата сверху, текст под ними, между сообщениями — линия.

`isMine` в новом компоненте нет: своё сообщение в теме выделять незачем, автор виден в шапке.

**Номер сообщения** считает страница, а не сервер: `v-for="(message, index) in themeMessages"` и `:number="index + 1"`. Лента идёт от старых к новым, новое сообщение дописывается `push()` в конец, поэтому у уже показанных сообщений номера не меняются. Колонка с номером в базе не нужна: номер — это положение в теме, и его всегда можно получить из порядка по `id`.

Форма отправки теперь одинакова на двух страницах. Выносить её в компонент пока не стали — об этом в разделе 27.

---

# Часть V. Тесты и проверка

## 24. Тесты

Тесты идут на SQLite в памяти (`phpunit.xml`), поэтому в запросах нет ничего, что есть только в PostgreSQL.

`tests/Feature/ClientGroupTest.php`. Проверяем: каталог показывает все группы со своим признаком участия; создатель сразу в группе; вступление и выход; страница группы открыта постороннему и показывает только свои темы; тему создаёт участник; посторонний получает 403 раньше валидации.

```php
<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Profile;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientGroupTest extends TestCase {
    use RefreshDatabase;

    /**
     * Упадёт, если каталог строить от $viewer->groups(), как на уроке:
     * группы, где смотрящего нет, в список не попадут.
     */
    public function test_groups_page_lists_all_groups_with_subscription_state(): void {
        $viewer = Profile::factory()->create();

        $joined = Group::factory()->create();
        $joined->subscribers()->attach($viewer->id);

        $other = Group::factory()->create();

        $this->actingAs($viewer->user)
            ->get(route('client.groups.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Group/Index')
                ->has('groups', 2)
                // latest('id'): группа, созданная последней, — первая в списке.
                ->where('groups.0.id', $other->id)
                ->where('groups.0.is_subscribed', false)
                ->where('groups.0.subscribers_count', 0)
                ->where('groups.1.id', $joined->id)
                ->where('groups.1.is_subscribed', true)
                ->where('groups.1.subscribers_count', 1)
                ->etc());
    }

    public function test_group_is_created_with_creator_as_subscriber(): void {
        $viewer = Profile::factory()->create();

        // post(), а не postJson(): форму отправляет Inertia и ждёт редирект.
        $response = $this->actingAs($viewer->user)
            ->post(route('client.groups.store'), [
                'title' => 'Laravel',
                'description' => 'Всё о фреймворке',
            ]);

        $group = Group::sole();

        $response->assertRedirect(route('client.groups.show', $group));

        $this->assertSame('Laravel', $group->title);
        // Вступать создатель не нажимал: его добавил GroupService.
        $this->assertTrue($group->hasSubscriber($viewer));
    }

    public function test_user_joins_and_leaves_group(): void {
        $viewer = Profile::factory()->create();
        $group = Group::factory()->create();

        // postJson: кнопка отправляет запрос через axios.
        $this->actingAs($viewer->user)
            ->postJson(route('client.groups.subscribers.toggle', $group))
            ->assertOk()
            ->assertJsonPath('is_subscribed', true)
            ->assertJsonPath('subscribers_count', 1);

        $this->actingAs($viewer->user)
            ->postJson(route('client.groups.subscribers.toggle', $group))
            ->assertOk()
            ->assertJsonPath('is_subscribed', false)
            ->assertJsonPath('subscribers_count', 0);

        $this->assertDatabaseCount('group_profile', 0);
    }

    /**
     * Смотрящий в группе не состоит: страницу он видеть должен, форму — нет.
     */
    public function test_group_page_shows_its_themes_to_non_subscriber(): void {
        $group = Group::factory()->create();
        Theme::factory()->for($group)->create(['title' => 'Первая тема']);

        // Тема другой группы: на этой странице её быть не должно.
        Theme::factory()->create();

        $this->actingAs(Profile::factory()->create()->user)
            ->get(route('client.groups.show', $group))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Group/Show')
                ->where('group.is_subscribed', false)
                ->has('themes', 1)
                ->where('themes.0.title', 'Первая тема')
                ->etc());
    }

    public function test_subscriber_creates_theme(): void {
        $viewer = Profile::factory()->create();
        $group = Group::factory()->create();
        $group->subscribers()->attach($viewer->id);

        $response = $this->actingAs($viewer->user)
            ->post(route('client.groups.themes.store', $group), [
                'title' => 'Вопросы новичков',
            ]);

        $theme = Theme::sole();

        $response->assertRedirect(route('client.themes.show', $theme));

        // Автор — из сессии, группа — из адреса: в форме ни того, ни другого нет.
        $this->assertDatabaseHas('themes', [
            'group_id' => $group->id,
            'author_id' => $viewer->id,
            'title' => 'Вопросы новичков',
        ]);
    }

    /**
     * Заголовок пустой намеренно. Если проверка участия стоит в authorize(),
     * посторонний получит 403 до валидации. Если её перенесут в контроллер,
     * валидация успеет раньше, и тест упадёт на редиректе с ошибкой.
     */
    public function test_non_subscriber_cannot_create_theme(): void {
        $group = Group::factory()->create();

        $this->actingAs(Profile::factory()->create()->user)
            ->post(route('client.groups.themes.store', $group), [
                'title' => '',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('themes', 0);
    }
}
```

`tests/Feature/ClientThemeTest.php`. Проверяем: участник пишет в тему; посторонний получает 403 раньше валидации; страница темы открыта постороннему и показывает сообщения только этой темы.

```php
<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\Theme;
use App\Models\ThemeMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientThemeTest extends TestCase {
    use RefreshDatabase;

    public function test_subscriber_sends_message(): void {
        $viewer = Profile::factory()->create();
        $theme = Theme::factory()->create();
        $theme->group->subscribers()->attach($viewer->id);

        $this->actingAs($viewer->user)
            ->postJson(route('client.themes.messages.store', $theme), [
                'content' => 'Привет!',
            ])
            ->assertCreated()
            ->assertJsonPath('content', 'Привет!')
            // Ник нужен сразу: иначе под новым сообщением будет «Аноним».
            ->assertJsonPath('author.nickname', $viewer->nickname);

        $this->assertDatabaseHas('theme_messages', [
            'theme_id' => $theme->id,
            'author_id' => $viewer->id,
            'content' => 'Привет!',
        ]);
    }

    /**
     * Текст пустой намеренно: 403 должен прийти раньше, чем 422.
     */
    public function test_non_subscriber_cannot_send_message(): void {
        $theme = Theme::factory()->create();

        $this->actingAs(Profile::factory()->create()->user)
            ->postJson(route('client.themes.messages.store', $theme), [
                'content' => '',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('theme_messages', 0);
    }

    public function test_theme_page_shows_its_messages_to_non_subscriber(): void {
        $author = Profile::factory()->create();
        $theme = Theme::factory()->create();

        ThemeMessage::factory()->for($theme)->for($author, 'author')->create(['content' => 'Первое']);
        ThemeMessage::factory()->for($theme)->for($author, 'author')->create(['content' => 'Второе']);

        // Сообщение из другой темы: на этой странице его быть не должно.
        ThemeMessage::factory()->create();

        // Смотрящий в группе не состоит: читать это не мешает,
        // а is_subscribed = false спрячет форму.
        $this->actingAs(Profile::factory()->create()->user)
            ->get(route('client.themes.show', $theme))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Theme/Show')
                ->where('theme.group.is_subscribed', false)
                ->has('messages', 2)
                ->where('messages.0.content', 'Первое')
                // Ник пришёл — значит, маппер загрузил автора.
                ->where('messages.0.author.nickname', $author->nickname)
                ->etc());
    }
}
```

**Чего здесь нет и почему.**

- **Автор из сессии для темы и сообщения**: `prepareForValidation()` устроен так же, как в `Message\StoreRequest`, это уже проверяет `ClientMessageTest::test_message_author_is_taken_from_session`.
- **Обязательное название группы и темы**: это встроенное правило `required`, своей логики в нём нет.
- **Пользователь без профиля**: ветки те же, что у подписки на профиль и групповых чатов.
- **Тестов Vue**: появление формы после «Вступить» и подсветку пункта в шапке проверяем руками (раздел 25).

## 25. Проверка

1. Запустить команды из раздела 1, заполнить миграции, `php artisan migrate` — появились таблицы `groups`, `themes`, `theme_messages`, `group_profile`.
2. Должен работать `composer run dev`. **Обновить страницу в браузере целиком** (F5): новые маршруты Ziggy отдаёт только при полной загрузке.
3. В шапке пункт «Группы». Клик — адрес `/groups`, пункт подсвечен, «Групп пока нет».
4. «Создать группу» → заполнить название и описание → «Создать». Адрес сменился на `/groups/N`. В шапке группы «Участников: 1» и кнопка «Выйти», ниже — форма новой темы.
5. В базе: строка в `groups`, строка в `group_profile` с профилем создателя.
6. Создать тему. Адрес — `/themes/N`, над заголовком ссылка «← название группы», пункт «Группы» в шапке подсвечен.
7. Отправить сообщение — оно встало в конец ленты с ником, датой и номером `#1`, в стиле комментария, а не пузыря чата. В DevTools → Network — `POST /themes/N/messages` с ответом `201`.
8. Войти вторым пользователем в другом браузере. На `/groups` группа с кнопкой «Вступить» и «Участников: 1».
9. Открыть группу: темы видны, вместо формы — «Создавать темы и писать в них могут участники группы». Открыть тему: сообщения видны, вместо формы — подсказка со ссылкой в группу.
10. Вернуться в группу и нажать «Вступить»: надпись «Выйти», «Участников: 2», форма новой темы появилась без перезагрузки. В Network — `POST /groups/N/subscribers` с `is_subscribed: true`.
11. Открыть тему и написать ответ — встал под первым сообщением с номером `#2`. У первого пользователя после перезагрузки страницы темы ответ на том же месте, с ником второго пользователя.
12. Второй пользователь в каталоге нажимает «Выйти» — «Участников: 1». Открыть тему заново — формы нет.
13. Проверить отказ на сервере: оставить открытой страницу темы с формой, в другой вкладке выйти из группы, вернуться и отправить сообщение — под полем «Не удалось отправить сообщение.», в Network ответ `403`.
14. `php artisan test --filter=ClientGroupTest` — шесть тестов зелёные.
15. `php artisan test --filter=ClientThemeTest` — три теста зелёные.
16. `php artisan test` — весь набор зелёный.
17. `vendor/bin/pint --dirty` — правок стиля нет (заготовки `make:class`, `make:request` и `make:controller` пишут скобки не в стиле проекта, Pint их поправит).

## 26. Грабли

- **`relation "groups" does not exist` при `migrate`** — миграция `themes` или `group_profile` оказалась старше `groups`: команды запускались не в том порядке. Переименуйте файл так, чтобы дата `groups` была раньше.
- **`relation "subscriber_subscribing" does not exist`** — в `Group::subscribers()` остались аргументы с урока. Для `group_profile` аргументы не нужны.
- **`relation "profile_group" does not exist`** (или наоборот) — таблица названа не по алфавиту. Конвенция — `group_profile`: иначе имя таблицы придётся передавать вторым аргументом в **обеих** связях.
- **`Call to undefined method App\Models\Group::factory()`** — в модели нет `use HasFactory`.
- **`Attempt to read property "id" on array`** при создании группы — перед редиректом группа превращена в массив через `resolve()`, как на уроке. В `route()` передаётся модель.
- **`Route [clients.group.show] not defined`** — имя маршрута из первой версии кода на видео. Нужно `client.groups.show`.
- **Создатель не может создать тему в своей группе** — в `GroupService` нет `attach()` создателя. Ловит `test_group_is_created_with_creator_as_subscriber`.
- **В каталоге только мои группы** — список строится от `$viewer->groups`, как на уроке, а не от `Group::query()`. Ловит `test_groups_page_lists_all_groups_with_subscription_state`.
- **Кнопка всегда «Вступить», хотя пользователь в группе** — в маппере нет `withExists()` / `loadExists()`: `whenHas()` не нашёл атрибут, ключа `is_subscribed` в JSON нет.
- **«Участников: 0» у всех групп** — нет `withCount('subscribers')` / `loadCount()`: `whenCounted()` не отдал ключ, и компонент подставил `0`.
- **Каталог делает запрос на каждую группу** — `is_subscribed` сделан аксессором, как на уроке. На экране всё верно, поэтому ошибку легко не заметить.
- **500 `Unique violation` на `group_profile` при втором нажатии** — в `toggleSubscribe()` вызван `attach()` вместо `toggle()`.
- **Нажал «Вступить», а форма темы не появилась** — страница не слушает `@toggled`, или `v-if` смотрит в проп `group.is_subscribed`, а не в локальное `isSubscribed`.
- **Посторонний создаёт темы или пишет в них** — в `authorize()` осталось `return true`. Ловят `test_non_subscriber_cannot_create_theme` и `test_non_subscriber_cannot_send_message`.
- **403 у всех, даже у участника** — в `authorize()` остался `false` из заготовки.
- **`Call to a member function hasSubscriber() on null`** — в маршруте параметр назван не `{group}` (или не `{theme}`), и `$this->route(...)` ничего не нашёл.
- **`Cannot read properties of undefined (reading 'title')`** на странице темы — `ThemeMapper` не загрузил `group`, и `whenLoaded()` не отдал ключ.
- **Под новым сообщением «Аноним»** — в `storeMessage()` нет `$message->load('author')`.
- **«Сообщений: » без числа в списке тем** — нет `withCount('messages')` в `GroupMapper::show()`.
- **Пункт «Группы» не подсвечен на странице темы** — в `route().current()` только `client.groups.*`.
- **`route 'client.groups.index' is not in the route list`** в консоли браузера — страница не обновлялась после добавления маршрутов. Нажать F5.
- **В тестах `Inertia page component file [Client/Group/Index] does not exist`** — нет Vue-файла или опечатка в пути.
- **Сообщение от другого участника не появилось без перезагрузки** — это не ошибка, а решение урока: сообщения тем без веб-сокетов.

## 27. Что можно сделать лучше

**Пагинация и поиск в каталоге.** Сейчас `/groups` отдаёт все группы разом. Для живой сети — `paginate(20)` и поле поиска с `whereLike('title', ...)`, как поиск профилей в 34-м уроке.

**«Мои группы».** Фильтр или вкладка на той же странице: `$viewer->groups()` с теми же `withCount()` и признаком участия.

**Первое сообщение вместе с темой.** Тема из одного заголовка пустая. Форма «заголовок + текст» и транзакция в сервисе: создать тему и её первое сообщение.

**Сообщения тем в реальном времени.** Как в 33-м уроке: событие `ShouldBroadcast`, канал `themes.{theme}.messages`, подписка через `echo()` на странице темы и `toOthers()` в контроллере.

**Общая форма отправки.** Форма в `Chat/Show` и `Theme/Show` теперь одинакова, отличается только адрес. Кандидат на компонент `MessageForm` с пропом `url` и событием `stored`, как `CommentForm`.

**Scope для признака участия.** Условие `'subscribers as is_subscribed' => ...` повторяется в трёх местах. Его можно вынести в локальный scope модели `Group`, чтобы маппер писал `->withIsSubscribed($viewer)`.

**Владелец группы.** Колонка `owner_id` в `groups` и права владельца: редактировать группу, удалять темы и сообщения. Правила «кто что может» тогда лучше собрать в `GroupPolicy`.

**Темы по активности.** `protected $touches = ['theme'];` в `ThemeMessage` обновит `themes.updated_at` при каждом сообщении, и список тем можно сортировать `latest('updated_at')`.

**Политики.** `GroupPolicy::createTheme()` и `ThemePolicy::sendMessage()`, внутри — `hasSubscriber()`. Тогда `authorize()` в Form Request превратится в `$this->user()->can(...)`.

## Задание

ГРУППЫ И ТЕМЫ

1. Создание групп и подписка на них (вступить в группу).
2. Создание тем (топиков), сообщений в темах.

- `php artisan make:model Group -m`
- `php artisan make:model Theme -m`
- `php artisan make:model ThemeMessage -m`
- `php artisan make:migration create_group_profile_table`

**Решения, принятые при подготовке урока:**

- `/groups` — каталог всех групп с кнопкой «Вступить / Выйти», а не только группы пользователя;
- смотреть группы, темы и сообщения могут все вошедшие, создавать темы и писать — только участники группы;
- создатель группы сразу становится участником, отдельного владельца у группы нет;
- сообщения в темах без веб-сокетов: отправка через axios, другие участники видят новое после перезагрузки;
- в коде подписка называется как в курсе (`subscribers`, `is_subscribed`, `toggleSubscribe`), в интерфейсе — «Вступить» и «Выйти».
