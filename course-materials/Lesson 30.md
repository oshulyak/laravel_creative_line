# Lesson 30 - Оповещения

Цель урока: у пользователя в шапке появляется колокольчик с числом непрочитанных уведомлений. Кто-то прокомментировал его пост, сделал репост или поставил лайк — число выросло. Клик по колокольчику подгружает список, каждая строка ведёт на нужный пост, а показанные уведомления становятся прочитанными.

По пути разбираются: **встроенная система уведомлений Laravel** — что она даёт и почему в этом проекте мы её сознательно не берём; **своя модель `Notification`** с получателем-профилем и полиморфным источником; **аксессор-счётчик** и разница между `$profile->notifications->count()` и `$profile->notifications()->count()`; **общие пропсы Inertia** (`HandleInertiaRequests::share()`) — единственный способ показать число на каждой странице, ничего не передавая из контроллеров; **почему модель нельзя отдавать наружу голышом** и зачем ради одного числа заводить отдельный ресурс; **загрузка по клику** через axios поверх Inertia-страницы; и, в домашнем задании, **обсерверы** — куда переезжает создание уведомлений и как в них же обновляется `read_at`. Там же выясняется, что у лайка нет события, на которое можно подписаться, — и появляется **модель поверх pivot-таблицы** (`->using()`).

Отправная точка — состояние после 29-го урока.

Главная мысль урока: **уведомление — это запись в базе, а не сообщение**. У него есть получатель, текст, источник и отметка о прочтении. Всё остальное — счётчик, попап, ссылки — вырастает из этих четырёх колонок. Письмо уходит и исчезает, уведомление лежит и ждёт.

## 1. Карта урока

| Файл | Что меняется |
| --- | --- |
| `database/migrations/..._create_app_notifications_table.php` | новая: таблица уведомлений (почему не `notifications` — раздел 3) |
| `app/Models/Notification.php` | новая модель: получатель, текст, источник, `read_at` |
| `app/Models/Profile.php` | связь `notifications()` + аксессор `notifications_count`, **ДЗ**: `->using(Like::class)` в трёх связях лайков |
| `app/Models/Post.php` | связь `notifications()` (morphMany), **ДЗ**: `->using(Like::class)` в связи лайков |
| `app/Models/Comment.php` | связь `notifications()` (morphMany), **ДЗ**: `->using(Like::class)` в связи лайков |
| `app/Http/Resources/Notification/NotificationResource.php` | новый: текст, дата, ссылка на источник |
| `app/Http/Resources/User/AuthUserResource.php` | новый: текущий пользователь для общих пропсов |
| `app/Http/Resources/Profile/ProfileWithNotificationsCountResource.php` | новый: профиль + счётчик непрочитанных |
| `app/Http/Middleware/HandleInertiaRequests.php` | `auth.user` — ресурс вместо модели |
| `routes/client.php` | маршрут `client.profiles.notifications.index` |
| `app/Http/Controllers/Client/ProfileController.php` | метод `indexNotification()` |
| `resources/js/Layouts/ClientLayout.vue` | колокольчик, счётчик, выпадающий список |
| `database/factories/NotificationFactory.php` | новая: нужна тестам |
| `app/Observers/CommentObserver.php` | **ДЗ**: уведомление о комментарии |
| `app/Observers/PostObserver.php` | **ДЗ**: уведомление о репосте |
| `app/Models/Like.php` | **ДЗ**: модель pivot-строки `likeables` — без неё у лайка нет событий |
| `app/Observers/LikeObserver.php` | **ДЗ**: уведомление о лайке |
| `app/Models/Image.php` | **ДЗ**: `->using(Like::class)` в связи лайков |
| `app/Observers/NotificationObserver.php` | **ДЗ**: простановка `read_at` |
| `tests/Feature/ClientNotificationTest.php` | новый набор тестов |

Команды для генерации файлов (запускаете вы):

```
php artisan make:model Notification
php artisan make:migration create_app_notifications_table --create=app_notifications
php artisan make:factory NotificationFactory --model=Notification
php artisan make:resource Notification/NotificationResource
php artisan make:resource User/AuthUserResource
php artisan make:resource Profile/ProfileWithNotificationsCountResource
php artisan make:model Like
php artisan make:observer CommentObserver --model=Comment
php artisan make:observer PostObserver --model=Post
php artisan make:observer LikeObserver --model=Like
php artisan make:observer NotificationObserver --model=Notification
php artisan make:test ClientNotificationTest --phpunit
```

Первые две строки в задании выглядят одной командой — `php artisan make:model Notification -m`. Мы её разделили: `-m` назвал бы миграцию и таблицу `notifications`, а нам нужна таблица `app_notifications` (почему — конец раздела 3). `--create=app_notifications` кладёт в заготовку миграции сразу нужное имя.

И после того, как миграция будет заполнена:

```
php artisan migrate
```

Отдельно о команде, которой в этом списке **нет**:

```
php artisan make:notifications-table    ← не запускаем
```

Почему — следующие два раздела.

---

# Часть I. Где живут уведомления

## 2. Задача

Письмо из 28-29 уроков решает половину задачи: автор поста узнаёт о комментарии, если откроет почту. Но социальная сеть так не работает — человек сидит на сайте, и узнавать о событиях он должен на сайте.

Что нужно:

- **счётчик** непрочитанного виден на каждой странице, а не на какой-то одной;
- **список** подгружается по клику, а не приезжает с каждой страницей: девяносто девять кликов из ста по колокольчику не делаются вовсе, и грузить эти данные заранее — трата запросов;
- у каждой строки есть **ссылка**: «прокомментировали ваш пост» без перехода к посту бесполезно;
- показанное уведомление становится **прочитанным**, и счётчик уменьшается.

Поводов для уведомления в проекте три, и все три — про чужое действие с твоей записью:

| Событие | Кого уведомляем | Откуда берётся |
| --- | --- | --- |
| комментарий к посту | автора поста | модель `Comment` создана |
| репост публикации | автора оригинала | модель `Post` создана с `parent_id` |
| лайк поста или комментария | автора записи | строка в pivot-таблице `likeables` |

Третья строка — отдельная история: лайк в проекте не модель, а запись в промежуточной таблице, и событий у неё по умолчанию нет вовсе. Этим займёмся в разделе 17.

Отсюда видно, что уведомление — это не «сообщение», а **строка в базе с пятью обязанностями**: знать получателя, знать инициатора (кто это сделал), нести текст, помнить источник (чтобы построить ссылку) и хранить отметку о прочтении.

## 3. Встроенные уведомления Laravel — и почему здесь своя модель

В Laravel уже есть готовая система уведомлений, и о ней нужно знать, даже если мы ей не пользуемся.

Выглядит она так:

```php
php artisan make:notification CommentAdded         // класс уведомления в app/Notifications
php artisan make:notifications-table               // миграция таблицы notifications
```

```php
class CommentAdded extends Notification {
    // По каким каналам доставлять: почта, база, broadcast, SMS, Slack.
    public function via(object $notifiable): array {
        return ['mail', 'database'];
    }

    // Что положить в JSON-колонку data, если канал — database.
    public function toArray(object $notifiable): array {
        return ['post_id' => $this->post->id, 'title' => $this->post->title];
    }
}

$user->notify(new CommentAdded($post, $comment));   // метод даёт трейт Notifiable
```

Трейт `Notifiable` у нашей `User` уже подключён — его поставил скелет Laravel, и через него же Breeze шлёт письма восстановления пароля.

Таблица, которую создаёт `make:notifications-table`:

| Колонка | Что в ней |
| --- | --- |
| `id` | uuid, а не число |
| `type` | полное имя класса уведомления |
| `notifiable_type` / `notifiable_id` | **получатель** (полиморфно: User, Team, кто угодно) |
| `data` | JSON со всем содержимым |
| `read_at` | отметка о прочтении |

**Что даёт встроенная система:** каналы из коробки (одно уведомление одновременно уходит письмом, в базу и в веб-сокет), очередь (`implements ShouldQueue`), готовые `unreadNotifications` и `markAsRead()`.

**Почему на этом уроке мы берём свою модель:**

1. **Источник уведомления негде хранить.** В нашей схеме важно знать, **на что** уведомление: комментарий, репост. Встроенная таблица кладёт это в JSON-колонку `data`, а JSON — не внешний ключ: по нему нет связи, нет `with()`, нет каскадного удаления, и запрос «все уведомления по этому посту» превращается в разбор строки. Нормализованная пара `notificationable_type` / `notificationable_id` даёт и связь, и eager-загрузку источника — на ней построен весь раздел 14.

2. **Учебная причина, главная.** Полиморфная связь, аксессоры, ресурсы, общие пропсы, обсерверы — всё это проще увидеть на своей модели из пяти колонок, чем внутри готового механизма.

**Чего в этом списке нет.** Напрашивается третий довод — «получатель у нас профиль, а встроенная система умеет только пользователей», — и он **неверен**. `notifiable_type` / `notifiable_id` полиморфны: трейт `Notifiable` подключается к любой модели, в том числе к `Profile`, и `$profile->notify(...)` работал бы точно так же. Так что причина не в получателе, а только в источнике и в учебной пользе.

Так что `make:notifications-table` мы не запускаем, но знаем, что он есть.

### Имя таблицы: `app_notifications`, а не `notifications`

Таблица у нас называется **`app_notifications`**. Это единственное место, где мы отходим от конвенции (по ней модель `Notification` искала бы таблицу `notifications`), и отход сознательный.

Причина в том, что имя `notifications` **уже занято** — его считает своим встроенная система Laravel. Конфликта имён классов при этом нет: наш `App\Models\Notification` и фреймворковый `Illuminate\Notifications\Notification` лежат в разных неймспейсах и ничего друг о друге не знают. А вот таблица одна на всех, и столкновение получилось бы такое:

- вместе с трейтом `Notifiable` (а он у `User` подключён) модель получает связь `$user->notifications`, жёстко нацеленную на таблицу `notifications` и на колонки `id (uuid)`, `type`, `data`. Наших колонок там нет — обращение упадёт;
- стоит кому-нибудь позже добавить встроенному уведомлению канал `database` (а это одна строка в методе `via()`), и Laravel начнёт писать в нашу таблицу, не найдя в ней `type` и `data`;
- в обратную сторону так же: `php artisan make:notifications-table`, запущенный по забывчивости, создаст миграцию поверх нашей таблицы.

Ни одна из этих неприятностей не проявится сразу: всё сломается через месяц, в чужой ветке, с сообщением про несуществующую колонку. Поэтому расходимся заранее — одним словом в имени таблицы.

Префикс `app_` читается как «таблица нашего приложения, а не фреймворка» — это распространённый приём ровно для такого случая. Имя модели при этом остаётся `Notification`, как просит задание; связать её с таблицей нужно явно — `protected $table` в разделе 5.

Альтернатива, которую можно было выбрать: переименовать модель (`AppNotification` → таблица `app_notifications` по конвенции, без `$table` вообще). Конвенция тогда не нарушается нигде, но имя класса расходится с формулировкой домашнего задания и лезет во все `use`-секции. Одна явная строка `protected $table` дешевле.

## 4. Таблица `app_notifications`

Модель и миграция создаются двумя командами:

```
php artisan make:model Notification
php artisan make:migration create_app_notifications_table --create=app_notifications
```

`--create=app_notifications` — «заготовка должна создавать вот эту таблицу»: Artisan подставит имя внутрь `Schema::create()` и `Schema::dropIfExists()`, и править их руками не придётся.

```php
    public function up(): void {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            // Получатель — профиль. Обычный внешний ключ, а не полиморфная связь:
            // уведомления в проекте получает только профиль, и выбирать тип не из чего.
            $table->foreignId('profile_id')->index()->constrained('profiles');
            // Инициатор: кто прокомментировал, репостнул, лайкнул. Тоже профиль.
            //
            // Колонка нужна не для красоты: без неё нельзя ответить на вопрос
            // «мы уже уведомляли ЭТОГО человека о ТАКОМ действии ЭТОГО профиля?»,
            // а он встаёт сразу же на лайках — их снимают и ставят заново
            // (раздел 17). Заодно она открывает дорогу к «Иван и ещё двое
            // оценили вашу публикацию» без переделки схемы.
            $table->foreignId('actor_id')->index()->constrained('profiles');
            // Готовый текст уведомления. Собираем его в момент создания, а не при показе:
            // «Новый комментарий к публикации "Заголовок"» должен остаться прежним,
            // даже если пост потом переименуют.
            $table->text('body');
            // Источник уведомления: комментарий или пост (репост). Ровно та же пара
            // колонок, что у commentable и likeable, — _type и _id плюс общий индекс.
            $table->morphs('notificationable');
            // Момент прочтения. NULL — не прочитано; именно по этой колонке считается
            // число на колокольчике.
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
        });
    }
```

Разбор по строкам.

**`foreignId('profile_id')->index()->constrained('profiles')`** — та же запись, что у `likeables.profile_id` и `comments.author_id`: колонка `bigint unsigned` плюс внешний ключ. `->index()` здесь не украшение: **PostgreSQL не создаёт индекс под внешний ключ автоматически** (в отличие от MySQL/InnoDB), а мы будем выбирать «уведомления этого профиля» на каждый запрос к шапке. Имя таблицы указано явно, потому что по имени колонки `profile_id` Laravel угадал бы `profiles` верно, но явная запись читается однозначно — так уже сделано в соседних миграциях.

**`text('body')`** — почему готовая строка, а не шаблон со сборкой при показе. Потому что уведомление — это запись о **факте на момент времени**. Пост переименовали, комментарий удалили — уведомление всё равно должно читаться. Альтернатива (JSON с параметрами и сборка текста в ресурсе) даёт локализацию и правку формулировок задним числом, но ровно эту сложность мы и уходили от встроенных уведомлений.

Имя колонки — `body`, как на уроке. В проекте тексты обычно зовутся `content` (у `Post`, у `Comment`), но там это «содержимое сущности», а здесь — «текст сообщения»; расхождение осознанное и совпадает с курсом.

**`morphs('notificationable')`** — две колонки `notificationable_type` / `notificationable_id` и составной индекс по ним. Слово выбрано длинное и грамматически корявое; более гладкое `notifiable` брать **нельзя**, и это не вкусовщина: в терминологии Laravel `notifiable` — это **получатель** уведомления, а у нас полиморфная связь указывает на **источник**. Одинаковое слово в двух противоположных смыслах в одном проекте — гарантированная путаница через месяц.

**`dateTime('read_at')->nullable()`** — «прочитано» хранится не флагом, а временем. Разница практическая: `boolean is_read` отвечает только «да/нет», а `read_at` заодно отвечает «когда», и непрочитанные выбираются тем же `whereNull('read_at')`. Так же устроена встроенная таблица Laravel, и это тот случай, когда чужое решение стоит скопировать.

Каскадного удаления (`->cascadeOnDelete()`) не ставим — в проекте его нет ни у одной связи. Но помнить стоит: уведомления — производные данные, и удаление профиля разумно уносит их с собой. Вернёмся к этому в разделе 22.

## 5. Модель `Notification`

`app/Models/Notification.php`:

```php
<?php

namespace App\Models;

use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Уведомление внутри приложения: кому, о чём и откуда.
 *
 * HasLog намеренно не подключён: уведомление — само по себе служебная запись,
 * и лог о создании лога никому не нужен. Тем более что в домашнем задании
 * уведомления начнут обновляться при каждом показе.
 */
class Notification extends Model {
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    /**
     * Имя таблицы указано явно: по конвенции модель Notification искала бы
     * таблицу notifications, а это имя занято встроенными уведомлениями
     * Laravel (см. раздел 3). Единственное место в проекте, где $table нужен.
     *
     * @var string
     */
    protected $table = 'app_notifications';

    /**
     * @var list<string>
     */
    protected $fillable = [
        // Получателя и инициатора подставляем руками: связь notifications()
        // на источнике знает про notificationable_*, но не про то, кому
        // адресовано и кем вызвано.
        'profile_id',
        'actor_id',
        'body',
        'read_at',
    ];

    /**
     * read_at — момент времени, а не строка: в коде это Carbon (можно сравнивать
     * и звать diffForHumans()), в JSON — ISO-8601.
     *
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'read_at' => 'datetime',
        ];
    }

    /**
     * Получатель уведомления (внешний ключ app_notifications.profile_id).
     */
    public function profile(): BelongsTo {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Инициатор события (внешний ключ app_notifications.actor_id).
     *
     * Вторая связь к той же модели Profile, и имя колонки приходится указать
     * явно: по имени метода actor() Eloquent искал бы колонку actor_id — здесь
     * конвенция как раз совпала, но написать стоит для читаемости пары
     * profile()/actor(), иначе их легко перепутать.
     */
    public function actor(): BelongsTo {
        return $this->belongsTo(Profile::class, 'actor_id');
    }

    /**
     * Источник: комментарий к посту, пост-репост либо лайкнутая запись.
     *
     * Обратная сторона morphMany на Post и Comment. Имя метода обязано совпадать
     * с первым аргументом morphs() в миграции — отсюда notificationable.
     */
    public function notificationable(): MorphTo {
        return $this->morphTo();
    }
}
```

Фабрика понадобится тестам, создаём сразу:

```
php artisan make:factory NotificationFactory --model=Notification
```

```php
    public function definition(): array {
        return [
            'profile_id' => Profile::factory(),
            'actor_id' => Profile::factory(),
            'body' => fake()->sentence(),
            // Источник по умолчанию — пост, хотя в жизни чаще встречается
            // комментарий. Причина в обсерверах из домашнего задания:
            // Comment::factory() внутри подняла бы CommentObserver, и вместе
            // с нужной строкой в базе появилась бы ВТОРАЯ, созданная обсервером.
            // Тест, считающий уведомления, сломался бы на ровном месте.
            // Post::factory() безопасен: PostObserver реагирует только на репосты
            // (parent_id заполнен), а обычный пост его не трогает.
            'notificationable_id' => Post::factory(),
            'notificationable_type' => Post::class,
            'read_at' => null,
        ];
    }
```

Форма записи та же, что у `CommentFactory`: полиморфная пара задаётся двумя ключами, и `->for(..., 'notificationable')` в тестах их переопределит.

## 6. Связи: получатель и источник

Три связи в трёх моделях — и вся схема замкнута.

`app/Models/Profile.php` — сторона получателя:

```php
    /**
     * Уведомления, адресованные этому профилю (внешний ключ app_notifications.profile_id).
     *
     * Обычный hasMany: имя колонки Laravel выведет из имени модели Profile,
     * указывать его не нужно — в отличие от posts() и comments(), где ключ
     * называется author_id.
     */
    public function notifications(): HasMany {
        return $this->hasMany(Notification::class);
    }
```

`app/Models/Comment.php` и `app/Models/Post.php` — сторона источника, код одинаковый:

```php
    /**
     * Уведомления, порождённые этой записью (Notificationable: одно ко многим).
     */
    public function notifications(): MorphMany {
        return $this->morphMany(Notification::class, 'notificationable');
    }
```

Зачем связь на источнике, если уведомления всегда читают со стороны профиля: ради создания. Строка

```php
$comment->notifications()->create(['profile_id' => $post->author_id, 'body' => '...']);
```

сама проставит `notificationable_type = App\Models\Comment` и `notificationable_id = $comment->id`. Без связи пришлось бы писать оба поля руками — и однажды ошибиться.

Итоговая картина:

```
          app_notifications
                 │
   profile_id ───┼──► profiles      «кому»   (belongsTo / hasMany)
     actor_id ───┼──► profiles      «от кого»(belongsTo)
                 │
 notificationable┼──► posts         «о чём»  (morphTo / morphMany)
                 └──► comments
```

Две колонки смотрят в одну таблицу `profiles`, и путать их нельзя: `profile_id` — тот, кто получит уведомление и увидит его на колокольчике; `actor_id` — тот, чьё действие его породило. У комментария это `$post->author_id` и `$comment->author_id` соответственно — то есть ровно те два значения, которые сравниваются в проверке «не уведомляем автора о его же действии».

## 7. Первое уведомление руками

Прежде чем писать интерфейс, полезно посмотреть на строку в базе. На уроке для этого используется одноразовая консольная команда; у нас уже есть `my:test`, но проще воспользоваться tinker (запускаете вы):

```
php artisan tinker
```

```php
$post = App\Models\Post::first();
$comment = $post->comments()->first();
$recipient = $post->author;                 // профиль автора поста

$comment->notifications()->create([
    'profile_id' => $recipient->id,          // кому — автору поста
    'actor_id' => $comment->author_id,       // от кого — автору комментария
    'body' => 'Новый комментарий к публикации «'.$post->title.'»',
]);
```

Проверьте таблицу:

```sql
select id, profile_id, actor_id, body, notificationable_type, notificationable_id, read_at from app_notifications;
```

Что в этой строке важно увидеть своими глазами:

- `notificationable_type` содержит **полное имя класса** — `App\Models\Comment`. Карта морф-типов (`Relation::enforceMorphMap()`) в проекте не настроена, ровно как у `commentable` и `likeable`;
- `read_at` — `NULL`. Это и есть «непрочитано»;
- `profile_id` — получатель, а **не** автор комментария; `actor_id` — наоборот. Их легко перепутать: комментарий написал один профиль, уведомление получает другой, и обе колонки ссылаются на одну таблицу `profiles`.

---

# Часть II. Счётчик в шапке

## 8. Сколько непрочитанных: аксессор на `Profile`

Число рядом с колокольчиком нужно **на каждой странице** клиентской части. Значит его надо уметь считать одной строкой откуда угодно.

`app/Models/Profile.php`:

```php
    /**
     * Количество НЕпрочитанных уведомлений профиля.
     *
     * Аксессор в новом стиле — так же, как is_admin у User: метод называется
     * notificationsCount(), а обращаться к нему нужно как к атрибуту
     * $profile->notifications_count (имя приводится к snake_case).
     *
     * notifications() со скобками, а не notifications: со скобками это запрос,
     * и в базу уходит SELECT COUNT(*) — одно число. Без скобок Eloquent сначала
     * вытащил бы ВСЕ строки уведомлений в память и посчитал их уже в PHP.
     * Для профиля с тысячей уведомлений разница — тысяча объектов на ровном месте.
     * В домашнем задании у этой мелочи появится второе, куда более неприятное
     * следствие — см. раздел 21.
     *
     * $value — значение, которое УЖЕ лежит в модели под этим именем: его кладёт
     * туда withCount('notifications as notifications_count'). Аксессор вызывается
     * раньше всего остального и перекрывает загруженное значение, поэтому без
     * этой проверки withCount не давал бы никакой экономии — счёт всё равно шёл бы
     * заново на каждую строку. Приведение к int нужно, потому что COUNT(*)
     * в PostgreSQL приезжает строкой.
     */
    protected function notificationsCount(): Attribute {
        return Attribute::make(
            get: fn (mixed $value): int => $value !== null
                ? (int) $value
                : $this->notifications()->whereNull('read_at')->count(),
        );
    }
```

На уроке это записано в старом стиле — `public function getNotificationsCountAttribute(): int`. Оба варианта рабочие и дают один и тот же атрибут `notifications_count`; в проекте принят новый (`Attribute::make`), он уже используется у `User::isAdmin()`, и разнобоя мы не заводим.

**Считаем непрочитанные, а не все.** На уроке в аксессоре стоит `count()` без условия — но тогда число на колокольчике никогда не уменьшится, и вся вторая часть домашнего задания (`read_at`) теряет смысл. `whereNull('read_at')` — то, что нужно интерфейсу.

**Цена решения.** Аксессор ходит в базу при каждом обращении. Обращение у нас одно на запрос (шапка), поэтому это один дополнительный `COUNT` — приемлемо.

**Как с ним уживается `withCount()`.** Если счётчик когда-нибудь понадобится для списка профилей, запрос строится так:

```php
Profile::withCount([
    'notifications as notifications_count' => fn (Builder $query) => $query->whereNull('read_at'),
])->get();
```

и благодаря `$value` в аксессоре лишних запросов не будет — значение приедет одним `JOIN`-подзапросом на всю выборку.

Два условия, без которых это не работает:

1. **Псевдоним обязателен** (`as notifications_count`): без него Laravel положит число под именем `notifications_count`… которое совпадает — но только потому, что связь называется `notifications`. Совпадение приятное, но полагаться стоит на явный псевдоним.
2. **Условие `whereNull('read_at')` обязано повторяться.** Голый `withCount('notifications')` посчитает **все** уведомления, аксессор примет это число как своё, и на колокольчике окажется «47» вместо «2». Ошибка тихая: ни исключения, ни лишнего запроса — просто неверное число.

Если держать эти два условия в голове тяжело (а это правда так), второй честный путь — **не делать аксессор вовсе** и всегда звать `withCount()` явно. Тогда забытый вызов даёт отсутствующий ключ, а не молча неправильное число. Мы оставляем аксессор: шапке нужен счётчик там, где никакого «запроса списка профилей» нет вообще — профиль приезжает из `$request->user()->profile`.

## 9. Общие пропсы Inertia: `share()`

Теперь число надо доставить в шапку. Шапка живёт в `ClientLayout.vue`, а раскладка **не получает пропсов от контроллера** — их получают страницы. Передавать `notifications_count` из каждого контроллера клиентской части (лента, профиль, пост) было бы четырьмя копиями одного и того же.

Для этого в Inertia есть общие пропсы — `app/Http/Middleware/HandleInertiaRequests.php`, метод `share()`. Всё, что он вернёт, приезжает **в каждый** Inertia-ответ приложения и доступно во Vue как `$page.props`. Именно так в проекте уже работает `auth.user` — его кладёт туда строка, которую поставил Breeze:

```php
    public function share(Request $request): array {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),        // ← вот это место
            ],
        ];
    }
```

Полезный приём для разглядывания: временно поставьте в начало `share()` вызов `dd($request)` и откройте любую страницу — видно, что метод зовётся на каждый запрос, до контроллера. На уроке это делается ровно так; не забудьте убрать.

## 10. Модель наружу не отдают: `AuthUserResource`

`'user' => $request->user()` — строка из стартового набора Breeze, и она же — приглашение к ошибке. В проп уходит **модель целиком**: Eloquent сериализует все колонки таблицы, кроме перечисленных в `$hidden`. Сейчас это `password` и `remember_token`, а значит наружу уже уезжают `email`, `phone`, `created_at` — каждому пользователю на каждой странице.

Правило проекта сформулировано ещё в уроках про API: **наружу сущность отдаёт ресурс**, а не модель. Ресурс — это явный список того, что клиент получит.

```
php artisan make:resource User/AuthUserResource
```

`app/Http/Resources/User/AuthUserResource.php`:

```php
<?php

namespace App\Http\Resources\User;

use App\Http\Resources\Profile\ProfileWithNotificationsCountResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Текущий пользователь для общих пропсов Inertia (auth.user).
 *
 * Отдельный ресурс, а не общий UserResource: тот обслуживает API
 * (Api\UserController отдаёт им список ВСЕХ пользователей), и профиль
 * со счётчиком уведомлений там не нужен — зато N+1 на сто строк был бы
 * обеспечен. Ресурс называет аудиторию: «пользователь для своей же шапки».
 */
class AuthUserResource extends JsonResource {
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // email и email_verified_at нужны не шапке, а форме настроек аккаунта
            // от Breeze: UpdateProfileInformationForm.vue читает их из этого же
            // пропа. Уберёте — форма приедет с пустым полем почты.
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            // Профиля может не быть: пользователь заводится регистрацией, профиль —
            // отдельная сущность. Тогда проп приедет null, и шапка это переживёт.
            'profile' => $this->profile
                ? ProfileWithNotificationsCountResource::make($this->profile)->resolve()
                : null,
        ];
    }
}
```

`HandleInertiaRequests::share()`:

```php
    public function share(Request $request): array {
        return [
            ...parent::share($request),
            'auth' => [
                // Тернарник обязателен: на странице логина и на главной пользователя нет,
                // а AuthUserResource::make(null) отдал бы пустой объект вместо null —
                // и проверка v-if="$page.props.auth.user" во Welcome.vue перестала бы работать.
                'user' => $request->user()
                    ? AuthUserResource::make($request->user())->resolve()
                    : null,
            ],
        ];
    }
```

Про `->resolve()`. У ресурса три способа отдать данные, и путать их не стоит:

| Вызов | Что возвращает |
| --- | --- |
| `Resource::make($model)` | объект ресурса; в JSON превратится сам, но это объект |
| `->resolve()` | **массив** — результат `toArray()` |
| `->response()` | полный HTTP-ответ с обёрткой `data`, кодом и заголовками |

В общих пропсах нужен массив: Inertia кладёт пропсы в JSON целиком, и лишняя обёртка `data` заставила бы писать во Vue `auth.user.data.name`. В проекте `->resolve()` уже используется везде, где ресурс встраивается внутрь другого ответа.

**Цена:** `$this->profile` — ленивая загрузка, то есть один дополнительный `SELECT` на каждый запрос приложения, плюс `COUNT` из аксессора. Два запроса ради числа в шапке — нормальная плата; если захочется убрать первый, поможет `$request->user()->loadMissing('profile')`.

## 11. Отдельный ресурс профиля со счётчиком

Напрашивается решение проще: добавить ключ прямо в существующий `ProfileResource`. На уроке этот путь как раз проходят — и сворачивают с него. Причина видна сразу, как только вспомнить, **где ещё** этот ресурс работает.

`ProfileResource` отдаёт автора внутри `PostResource` и внутри `CommentResource`. Открытая лента — это десять карточек, у каждой автор; страница поста — ещё и двадцать комментариев. Ключ `notifications_count` в общем ресурсе означал бы **`COUNT` на каждого автора в списке**: тридцать лишних запросов ради числа, которое интерфейсу в этих местах не нужно вовсе. Плюс неприятная мелочь — количество чужих уведомлений видел бы любой читатель ленты.

Поэтому — отдельный ресурс:

```
php artisan make:resource Profile/ProfileWithNotificationsCountResource
```

```php
<?php

namespace App\Http\Resources\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Профиль для шапки: минимум полей плюс счётчик непрочитанных уведомлений.
 *
 * Длинное имя — не стеснение, а точное описание: ресурс отличается от
 * ProfileResource ровно одним ключом, и имя говорит, каким. Так же назван
 * ресурс на уроке.
 */
class ProfileWithNotificationsCountResource extends JsonResource {
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            // Атрибута такого в таблице нет — его считает аксессор
            // Profile::notificationsCount(). Для ресурса разницы никакой:
            // $this->notifications_count работает одинаково и для колонки,
            // и для аксессора.
            'notifications_count' => $this->notifications_count,
        ];
    }
}
```

Типичная ошибка на этом шаге (её на уроке видно на экране): написать внутри ресурса `$this->profile->notifications_count`. Внутри `ProfileWithNotificationsCountResource` **`$this` уже и есть профиль** — ресурс проксирует обращения к своей модели. Лишний `->profile` уведёт в связь `profiles.profile`, которой не существует.

**Развилка, о которой стоит знать.** Второй честный способ — оставить один `ProfileResource` и сделать ключ условным:

```php
'notifications_count' => $this->whenCounted('notifications'),
```

Ключ появляется только там, где запрос звали с `withCount()`: `whenCounted()` смотрит в **сырой массив атрибутов модели** (`getAttributes()`), а туда аксессор не попадает — значит в ленте и в карточках комментариев ключа не будет, как и сейчас. Это ровно та же механика, что у `likes_count` у постов.

Но одной строкой в ресурсе дело не ограничится: вместе с ней придётся дописать `withCount(['notifications as notifications_count' => ...])` **в каждый** контроллер, который отдаёт профиль шапке, и повторить там условие `whereNull('read_at')` (см. предыдущий раздел). Забыть — значит получить либо пропавший счётчик, либо неверное число.

Отдельный ресурс этого не требует вовсе: он самодостаточен, и вызывающий код о нём не думает. На уроке выбран он, и мы следуем уроку.

## 12. Колокольчик в `ClientLayout.vue`

Разметка шапки сейчас — две ссылки и имя пользователя. Добавляем блок с колокольчиком; попап пока заполним заглушкой, данные подключим в следующей части.

```vue
                <!--
                    relative на обёртке — точка отсчёта для absolute-позиционирования
                    и счётчика, и выпадающего списка. Без неё попап уедет
                    относительно всей страницы.
                -->
                <div class="relative ml-auto flex items-center gap-4">
                    <button
                        type="button"
                        class="relative text-gray-500 hover:text-sky-700"
                        @click="toggleNotifications"
                    >
                        <svg
                            class="h-6 w-6"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"
                            />
                        </svg>

                        <!--
                            v-if, а не текст «0»: пустой колокольчик не должен
                            кричать нулём. Условие по числу, а не по наличию ключа.
                        -->
                        <span
                            v-if="unreadCount > 0"
                            class="absolute -right-2 -top-2 rounded-full bg-red-600 px-1.5 text-xs font-semibold text-white"
                        >
                            {{ unreadCount }}
                        </span>
                    </button>

                    <span class="text-sm text-gray-500">
                        {{ $page.props.auth.user.name }}
                    </span>

                    <!-- Выпадающий список: содержимое появится в разделе 15. -->
                    <div
                        v-if="isPopupShown"
                        class="absolute right-0 top-10 z-10 w-80 rounded-lg border border-gray-200 bg-white p-3 shadow-lg"
                    >
                        <p class="text-sm text-gray-500">Здесь будут уведомления.</p>
                    </div>
                </div>
```

```js
export default {
    name: 'ClientLayout',
    components: { Link },
    data() {
        return {
            isPopupShown: false,
        };
    },
    computed: {
        // Счётчик берём из общих пропсов. Optional chaining обязателен:
        // у пользователя может не быть профиля, и тогда profile === null.
        unreadCount() {
            return this.$page.props.auth.user.profile?.notifications_count ?? 0;
        },
    },
    methods: {
        toggleNotifications() {
            this.isPopupShown = !this.isPopupShown;
        },
    },
};
```

Два места, где легко споткнуться:

- **`$page` доступен в шаблоне без импорта**, а в `<script>` — только как `this.$page`. Это глобальное свойство, которое плагин Inertia добавляет ко всем компонентам;
- **раскладка живёт дольше страницы.** Inertia не пересоздаёт `layout` при переходе между страницами — меняется только содержимое `<slot />`. Значит `isPopupShown` переживёт переход из ленты в профиль, и открытый попап останется открытым. Для попапа это скорее удобно, но помнить о свойстве нужно: локальное состояние раскладки не сбрасывается никогда.

---

# Часть III. Список по клику

## 13. Маршрут и метод контроллера

`routes/client.php`, рядом с остальными маршрутами профиля:

```php
    // Уведомления текущего пользователя. Адрес без id — как у profiles/personal:
    // чьи уведомления показывать, сервер знает из сессии. Просить их «по номеру
    // профиля» было бы приглашением подставить чужой номер.
    //
    // Порядок относительно profiles/{profile} роли не играет: на том маршруте
    // стоит whereNumber(), и слово notifications в параметр не попадёт.
    Route::get('profiles/notifications', [ProfileController::class, 'indexNotification'])
        ->name('client.profiles.notifications.index');
```

`app/Http/Controllers/Client/ProfileController.php`:

```php
    /**
     * Уведомления текущего пользователя.
     *
     * Возвращает массив, а не Inertia-страницу: за списком ходит axios из шапки,
     * страница при этом остаётся на месте. Тот же приём, что у списка комментариев.
     *
     * Пагинации нет намеренно: попап показывает последние два десятка, «всю историю
     * уведомлений» задание не требует. limit() вместо paginate() — честнее, чем
     * пагинатор, чьи links и meta никто не прочитает.
     *
     * Прочитанными строки здесь НЕ помечаются: этот метод только читает. Где
     * появится пометка — решается в разделе 18; от выбранного там варианта
     * зависит, останется ли этот метод чистым.
     *
     * Двадцать — это и предел показа, и предел «прочтения»: пометить можно только
     * то, что человек увидел, а увидел он ровно эти строки.
     *
     * @return array<int, array<string, mixed>>
     */
    public function indexNotification(Request $request): array {
        $profile = $request->user()->profile;

        abort_if($profile === null, 404);

        $notifications = $profile->notifications()
            // Источник нужен ресурсу, чтобы построить ссылку. Без with() двадцать
            // уведомлений дали бы двадцать лишних запросов (N+1) — при полиморфной
            // связи Eloquent сгруппирует их по типу и сделает по одному на тип.
            ->with('notificationable')
            ->latest('id')
            ->limit(20)
            ->get();

        return NotificationResource::collection($notifications)->resolve();
    }
```

Про имя метода. `indexNotification` в `ProfileController` — как на уроке; по ресурсной конвенции это был бы `Client\NotificationController::index()`. Пока метод один, отдельный контроллер — пустая папка с одной строкой. Как только появится «пометить прочитанным» или «удалить», уведомления станут самостоятельным ресурсом, и контроллер заведём. Это то же рассуждение, по которому в 29-м уроке событие `CommentCreated` отложили до второго слушателя.

`->resolve()` на коллекции ресурсов отдаёт **массив массивов**, без обёртки `data`. Клиент получит `[{...}, {...}]`, и во Vue не придётся писать `res.data.data`.

## 14. `NotificationResource` и ссылка на источник

```
php artisan make:resource Notification/NotificationResource
```

```php
<?php

namespace App\Http\Resources\Notification;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource {
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'body' => $this->body,
            // Клиенту отдаём готовый признак, а не время: попапу нужно только
            // подсветить новые строки жирным.
            'is_read' => $this->read_at !== null,
            // created_at Laravel кастует сам, в JSON уедет ISO-8601.
            'created_at' => $this->created_at,
            'url' => $this->buildUrl(),
        ];
    }

    /**
     * Куда ведёт уведомление.
     *
     * Ссылку строит сервер, а не клиент: только здесь известно, что источник —
     * комментарий, и что показать надо пост, которому этот комментарий принадлежит.
     * Собирать такой разбор во Vue значило бы продублировать знание о схеме данных.
     *
     * null — допустимое значение: источник могли удалить, и тогда строка
     * показывается без ссылки, а не ведёт в 404.
     */
    private function buildUrl(): ?string {
        $source = $this->notificationable;

        return match (true) {
            // Репост — это пост, и вести он должен на себя.
            $source instanceof Post => route('client.posts.show', $source),
            // Комментарий сам по себе страницы не имеет: ведём на пост,
            // к которому он оставлен.
            $source instanceof Comment && $source->commentable instanceof Post
                => route('client.posts.show', $source->commentable),
            default => null,
        };
    }
}
```

`match (true)` вместо цепочки `if` — форма записи «первое подошедшее условие»: читается как таблица «тип источника → адрес». Возвращаемый тип `?string` честно говорит, что адреса может не быть.

Одна известная неэффективность: `$source->commentable` у комментария — отдельный запрос на каждую строку списка. `with('notificationable')` подгружает сам источник, но не связь внутри него. Лечится `morphWith`:

```php
->with(['notificationable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
    Comment::class => ['commentable'],
])])
```

Для двадцати строк в попапе разница невелика, поэтому в основной код это не берём — но знать конструкцию полезно, это стандартный ответ на N+1 в полиморфных связях.

## 15. Попап: axios, загрузка, счётчик после прочтения

Дополняем `ClientLayout.vue`. Разметка попапа:

```vue
                    <div
                        v-if="isPopupShown"
                        class="absolute right-0 top-10 z-10 w-80 rounded-lg border border-gray-200 bg-white p-3 shadow-lg"
                    >
                        <p v-if="isLoading" class="py-2 text-sm text-gray-500">Загружаю…</p>

                        <p v-else-if="!notifications.length" class="py-2 text-sm text-gray-500">
                            Уведомлений пока нет.
                        </p>

                        <ul v-else class="divide-y divide-gray-100">
                            <li v-for="notification in notifications" :key="notification.id" class="py-2">
                                <!--
                                    Link, а не <a>: переход внутри приложения должен
                                    остаться SPA-переходом. Попап закрываем сами —
                                    раскладка при переходе не пересоздаётся, и открытым
                                    он бы так и остался поверх новой страницы.
                                -->
                                <Link
                                    v-if="notification.url"
                                    :href="notification.url"
                                    class="block text-sm hover:text-sky-700"
                                    :class="notification.is_read ? 'text-gray-500' : 'font-medium text-gray-900'"
                                    @click="isPopupShown = false"
                                >
                                    {{ notification.body }}
                                </Link>

                                <span v-else class="block text-sm text-gray-500">
                                    {{ notification.body }}
                                </span>
                            </li>
                        </ul>

                        <button
                            type="button"
                            class="mt-2 w-full border-t border-gray-200 pt-2 text-center text-sm text-gray-500 hover:text-gray-900"
                            @click="isPopupShown = false"
                        >
                            Закрыть
                        </button>
                    </div>
```

Скрипт:

```js
import axios from 'axios';
import { Link, router } from '@inertiajs/vue3';

export default {
    name: 'ClientLayout',
    components: { Link },
    data() {
        return {
            isPopupShown: false,
            isLoading: false,
            notifications: [],
        };
    },
    computed: {
        unreadCount() {
            return this.$page.props.auth.user.profile?.notifications_count ?? 0;
        },
    },
    methods: {
        // Клик по колокольчику: закрыть, если открыт; открыть и загрузить, если закрыт.
        toggleNotifications() {
            if (this.isPopupShown) {
                this.isPopupShown = false;

                return;
            }

            this.isPopupShown = true;
            this.loadNotifications();
        },

        // Данные грузим КАЖДЫЙ раз при открытии, а не один раз за сессию страницы:
        // раскладка живёт до перезагрузки браузера, и закэшированный список
        // устарел бы уже через минуту.
        loadNotifications() {
            this.isLoading = true;

            axios
                .get(route('client.profiles.notifications.index'))
                .then((response) => {
                    this.notifications = response.data;

                    // Показанные уведомления стали прочитанными (как именно —
                    // раздел 18), но число в шапке приехало со страницей и об этом
                    // не знает. Частичная перезагрузка просит Inertia обновить
                    // ТОЛЬКО проп auth: страница не перерисовывается, запрос уходит
                    // один и маленький.
                    router.reload({ only: ['auth'] });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
    },
};
```

Про `router.reload({ only: ['auth'] })`. Это «частичная перезагрузка» Inertia: запрос уходит на текущий адрес с заголовком `X-Inertia-Partial-Data: auth`, сервер отдаёт только этот проп, клиент сливает его с тем, что уже есть. Альтернатива — обнулить счётчик локальной переменной — короче, но начинает врать сразу в двух местах: раскладка не пересоздаётся при переходах, поэтому «ноль» останется на экране и когда придут новые уведомления, и когда непрочитанных было больше, чем поместилось в попап. Здесь лучше спросить сервер.

Порядок действий по клику получается такой:

```
клик ─► isPopupShown = true, isLoading = true
     ─► GET /profiles/notifications
            сервер: выбрал 20 строк, отдал JSON
     ─► список отрисован
     ─► показанные помечены прочитанными (как именно — раздел 18)
     ─► router.reload({ only: ['auth'] }) ─► счётчик уменьшился на число показанных
```

Обратите внимание на предпоследнюю строку: счётчик становится нулём **не всегда**. Попап отдаёт двадцать последних, и если непрочитанных было тридцать пять, на колокольчике останется пятнадцать — те, что пользователь ещё не видел. Это не недоделка, а выбранная семантика: прочитано ровно то, что показано (раздел 18).

Сама пометка — вторая часть домашнего задания; до неё счётчик после перезагрузки останется прежним, и это нормально. Если вы реализуете её вариантом А (отдельный `PATCH`), между отрисовкой и `reload` добавится ещё один запрос:

```js
                .then((response) => {
                    this.notifications = response.data;

                    return axios.patch(route('client.profiles.notifications.read'), {
                        ids: response.data.map((notification) => notification.id),
                    });
                })
                .then(() => {
                    router.reload({ only: ['auth'] });
                })
```

При варианте Б или В этого блока не нужно: пометка уже произошла на сервере внутри `GET`-запроса.

---

# Часть IV. Домашнее задание

## 16. Обсерверы: где живут и как включаются

**Обсервер — это класс, собирающий в одном месте реакции на события модели.** События у Eloquent такие: `retrieved`, `creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`, `deleted`, `restored`, `forceDeleted`. Имя метода в обсервере = имя события, единственный аргумент — сама модель.

В проекте с событиями моделей мы уже работали: трейт `HasLog` подписывается на `created`, `updated`, `retrieved`, `deleted` и пишет файлы логов. Обсервер — тот же механизм, но вынесенный в отдельный класс.

```
php artisan make:observer CommentObserver --model=Comment
```

Класс попадает в `app/Observers/` (папку Artisan создаст сам). Подключается атрибутом на модели:

```php
use App\Observers\CommentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy(CommentObserver::class)]
class Comment extends Model {
```

Второй способ — `Comment::observe(CommentObserver::class)` в `AppServiceProvider::boot()`. Атрибут в этом проекте уместнее: в `User` и `Post` уже висят `#[Fillable]`, `#[Hidden]`, у консольных команд — `#[Signature]`. Плюс связь «модель → её обсервер» видно прямо в модели, а не в провайдере.

**Главное свойство обсервера, ради которого он здесь и нужен:** он срабатывает на **любое** создание модели — из контроллера, из консольной команды, из сидера, из фабрики в тесте. Это одновременно и сила («забыть уведомить» невозможно), и ловушка (сидеры начнут плодить уведомления). К ловушке вернёмся в разделе 21.

## 17. ДЗ-1: кто создаёт уведомления

Задание: уведомлять автора о **комментариях к его постам** и о **репостах его постов**. Сверх задания курса добавляем третий повод — **лайки** постов и комментариев; он интереснее обоих предыдущих, потому что лайк в проекте не модель.

Где это писать — три варианта, и выбор не самоочевиден:

| Где | Плюсы | Минусы |
| --- | --- | --- |
| В контроллере, рядом с `dispatch()` письма | видно в месте действия; легко добавить условие | комментарий, созданный командой или сидером, уведомления не породит; правило размазано по контроллерам |
| В обсервере модели | срабатывает всегда, независимо от источника; правило в одном месте | «невидимое» поведение: читая контроллер, о нём не догадаешься |
| Событие + слушатели | несколько независимых реакций на одно действие | три файла ради одной строки — рано |

Задание прямо указывает на обсервер (вторая часть — про обсервер), и для уведомлений это осмысленно: уведомление должно появляться от **факта** «комментарий создан», а не от того, каким маршрутом его создали.

`app/Observers/CommentObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\Comment;
use App\Models\Post;

class CommentObserver {
    /**
     * Комментарий создан — уведомляем автора публикации.
     */
    public function created(Comment $comment): void {
        $post = $comment->commentable;

        // Ответы в ветке пропускаем: задание говорит про комментарии к постам.
        // Уведомление автору родительского комментария — отдельная задача,
        // и решается она здесь же одной веткой, когда понадобится.
        if (! $post instanceof Post) {
            return;
        }

        // Себе о себе не пишем — то же правило, что у письма в CommentController.
        if ($post->author_id === $comment->author_id) {
            return;
        }

        // create() на полиморфной связи сам проставит notificationable_type
        // и notificationable_id. Наше дело — получатель, инициатор и текст.
        $comment->notifications()->create([
            'profile_id' => $post->author_id,
            'actor_id' => $comment->author_id,
            'body' => 'Новый комментарий к публикации «'.$post->title.'»',
        ]);
    }
}
```

`app/Observers/PostObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\Post;

class PostObserver {
    /**
     * Пост создан. Уведомление нужно только если это репост: у обычной
     * публикации нет адресата.
     */
    public function created(Post $post): void {
        $original = $post->parent;

        if ($original === null) {
            return;
        }

        if ($original->author_id === $post->author_id) {
            return;
        }

        $post->notifications()->create([
            'profile_id' => $original->author_id,
            'actor_id' => $post->author_id,
            'body' => 'Репост публикации «'.$original->title.'»',
        ]);
    }
}
```

Обратите внимание: `$post->parent` — связь, добавленная в 27-м уроке; для обычного поста она вернёт `null`, и обсервер молча выйдет. Никаких `if ($post->parent_id !== null)` по колонке — связь читается лучше.

Атрибуты на моделях:

```php
#[ObservedBy(CommentObserver::class)]
class Comment extends Model {
```

```php
#[ObservedBy(PostObserver::class)]
class Post extends Model {
```

**Что теперь стоит вычистить.** Уведомление о комментарии переехало в обсервер, а условие «не писать автору о его же комментарии» осталось и в `CommentController::store()` — там оно относится к **письму**. Это не дублирование: письмо и уведомление — две разные доставки одного факта, и каждая решает сама. Но когда захочется свести их вместе, правильный ход — событие `CommentCreated` с двумя слушателями (письмо и уведомление), о чём говорилось ещё в 29-м уроке.

### Лайки: у pivot-строки нет событий

С третьим поводом так просто не выйдет. Лайк в проекте — это строка в таблице `likeables`, которую ставит `toggle()` на связи:

```php
$changes = $post->likedByProfiles()->toggle($profile->id);
```

Никакой модели `Like` не существует, а значит не существует и события `created`, на которое мог бы подписаться обсервер. Есть два выхода.

**Выход первый: писать уведомление прямо в контроллерах.** Пять строк в `PostController::toggleLike()` и столько же в `CommentController::toggleLike()`. Работает, но правило про лайки оказывается не там, где правила про комментарии и репосты, — а искать его будут именно там.

**Выход второй, который и берём: дать pivot-строке модель.** Laravel это умеет: связи `belongsToMany` / `morphToMany` принимают `->using(ИмяКласса::class)`, и тогда `attach()` перестаёт делать «голый» `INSERT`, а создаёт объект и зовёт у него `save()`. А `save()` — это уже обычный Eloquent со всеми событиями, включая `created`.

`php artisan make:model Like`, затем правим заготовку:

```php
<?php

namespace App\Models;

use App\Observers\LikeObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * Строка полиморфного pivot likeables: «профиль лайкнул запись».
 *
 * MorphPivot, а не Model: у полиморфной промежуточной таблицы есть тип
 * (likeable_type), и обычный Pivot о нём не знает.
 *
 * Модель заводится ради ОДНОГО — событий. Без неё attach() пишет строку
 * напрямую запросом, и подписаться на лайк нечем.
 */
#[ObservedBy(LikeObserver::class)]
class Like extends MorphPivot {
    /**
     * Таблица не выводится из имени класса: Like дал бы likes.
     *
     * @var string
     */
    protected $table = 'likeables';

    /**
     * У likeables есть собственный id() — значит ключ автоинкрементный.
     * У обычного pivot его нет, и по умолчанию Laravel считает иначе.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Что лайкнули: пост, комментарий или изображение.
     */
    public function likeable(): MorphTo {
        return $this->morphTo();
    }

    /**
     * Кто лайкнул.
     */
    public function profile(): BelongsTo {
        return $this->belongsTo(Profile::class);
    }
}
```

Дальше `->using(Like::class)` дописывается в связи лайков — в `Post`, `Comment`, `Image` и в три связи `Profile`:

```php
    public function likedByProfiles(): MorphToMany {
        return $this->morphToMany(Profile::class, 'likeable')
            ->using(Like::class)
            ->withTimestamps();
    }
```

Существующий код лайков при этом не меняется вовсе: `toggle()`, `withCount('likedByProfiles')`, `withExists(... as is_liked)` работают как работали. Меняется только то, что происходит внутри `attach()` и `detach()`.

`app/Observers/LikeObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;

class LikeObserver {
    /**
     * Лайк поставлен — уведомляем автора записи.
     */
    public function created(Like $like): void {
        $target = $like->likeable;

        // Лайкать можно ещё и изображения, но у Image нет автора-профиля,
        // и уведомлять некого. Проверка по типам заодно объясняет, почему
        // дальше можно спокойно брать author_id: он есть и у поста, и
        // у комментария.
        if (! $target instanceof Post && ! $target instanceof Comment) {
            return;
        }

        if ($target->author_id === $like->profile_id) {
            return;
        }

        $body = $target instanceof Post
            ? 'Новый лайк публикации «'.$target->title.'»'
            : 'Новый лайк вашего комментария';

        // firstOrCreate, а не create: лайк можно снять и поставить заново
        // сколько угодно раз, и каждый повтор — это событие created.
        // Первый массив — условия поиска, второй — то, что запишется, если
        // подходящей строки нет.
        //
        // Ищем по тройке «кому + от кого + ещё не прочитано»; связь сама
        // добавит в условие notificationable_type и notificationable_id,
        // то есть конкретную запись. Пользователь увидел уведомление —
        // read_at заполнился, и следующий лайк того же человека создаст
        // новую строку. Это верно: событие действительно новое.
        $target->notifications()->firstOrCreate(
            [
                'profile_id' => $target->author_id,
                'actor_id' => $like->profile_id,
                'read_at' => null,
            ],
            [
                'body' => $body,
            ],
        );
    }
}
```

Три вещи, которые стоит здесь заметить.

**Источником пишем сам пост или комментарий, а не лайк.** `$target->notifications()`, а не `$like->notifications()`. Причина практическая: лайк снимают, строка в `likeables` исчезает, и уведомление осталось бы с ссылкой в никуда — `buildUrl()` вернул бы `null`, и строка в попапе перестала бы открываться. Пост же никуда не денется. Побочный плюс: `NotificationResource` из раздела 14 уже умеет строить ссылку для `Post` и для `Comment`, и дописывать в него ничего не нужно.

**Различить лайк и репост можно только по тексту.** У обоих `notificationable_type = App\Models\Post`, и отличаются они лишь строкой `body`. Пока уведомления только показываются человеком — этого достаточно; как только понадобится фильтр «показать только лайки», в таблицу придётся добавить колонку `type`.

**Дедупликация ограничена лайками.** В `CommentObserver` и `PostObserver` `firstOrCreate` не нужен: у каждого комментария и каждого репоста свой `notificationable_id`, и две строки там означают два разных события. Лайк — единственное место, где одно и то же действие повторяется над одной и той же записью.

## 18. ДЗ-2: `read_at` — три варианта

Формулировка задания: «продумать и реализовать вариант обновления оповещений в обсервере (`read_at`)». Значит сначала — продумать.

### Сначала о семантике: что считаем прочитанным

Прежде чем выбирать механизм, нужно ответить на вопрос, который легко проскочить: **что именно помечается прочитанным при открытии попапа?**

- «**Всё непрочитанное**» — счётчик после открытия всегда ноль. Просто, но нечестно: попап показывает двадцать последних, а если непрочитанных тридцать пять, пятнадцать из них пользователь не видел никогда, и узнать о них ему больше не из чего;
- «**Только показанное**» — прочитанными становятся ровно те строки, которые уехали в ответ. Счётчик после открытия уменьшается на двадцать, и остаток видно на колокольчике: есть что долистать.

Берём второе. Оно совпадает с тем, что человек реально увидел, и одинаково выражается во всех трёх вариантах ниже — а значит, выбор механизма не меняет поведение приложения.

### Вариант А. Отдельный запрос «пометить прочитанными»

Маршрут `PATCH /profiles/notifications/read`, который клиент дёргает **после** того, как получил и показал список:

```php
    Route::patch('profiles/notifications/read', [ProfileController::class, 'readNotification'])
        ->name('client.profiles.notifications.read');
```

```php
    /**
     * Пометить показанные уведомления прочитанными.
     *
     * Список id присылает клиент: он точно знает, что именно отрисовал.
     * whereKey() ограничивает обновление ими, а связь notifications() —
     * профилем текущего пользователя: чужую строку не пометить даже
     * подобранным id.
     *
     * @return array<string, int>
     */
    public function readNotification(ReadRequest $request): array {
        $profile = $request->user()->profile;

        abort_if($profile === null, 404);

        $marked = $profile->notifications()
            ->whereKey($request->validated('ids'))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return ['marked' => $marked];
    }
```

`ReadRequest` (`php artisan make:request Client/Notification/ReadRequest`) проверяет только форму списка — принадлежность строк профилю проверяет сам запрос к связи, а не валидация:

```php
    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ];
    }
```

**Плюсы:** один `UPDATE` на все строки; `GET` остаётся безопасным — читает и ничего не меняет; помечается ровно показанное. **Минусы:** маршрут, `FormRequest` на массив id и второй запрос из браузера.

### Вариант Б. Пометка внутри того же `GET`-контроллера

```php
$notifications = $profile->notifications()->with('notificationable')->latest('id')->limit(20)->get();

// Ответ собираем ДО пометки: клиент должен увидеть, какие строки были новыми.
$data = NotificationResource::collection($notifications)->resolve();

// whereKey по показанным id, а не whereNull по всем: помечаем только то,
// что реально уехало в ответ.
$profile->notifications()
    ->whereKey($notifications->modelKeys())
    ->whereNull('read_at')
    ->update(['read_at' => now()]);

return $data;
```

Короче варианта А на целый маршрут, порядок действий виден в одном методе. Но **`GET` начинает менять данные**, а это нарушение семантики HTTP с практическими последствиями: браузеры и прокси считают `GET` безопасным, повторяют и префетчат его. Достаточно кому-нибудь навести мышь на ссылку с `prefetch`, чтобы уведомления «прочитались» без участия человека.

### Вариант В. Обсервер на событие `retrieved` — то, что просит задание

`retrieved` срабатывает **в момент, когда модель достали из базы**. То есть «показали = прочитали», и никакому вызывающему коду про это помнить не нужно.

```
php artisan make:observer NotificationObserver --model=Notification
```

```php
<?php

namespace App\Observers;

use App\Models\Notification;

class NotificationObserver {
    /**
     * Уведомление достали из базы — значит его показали, значит оно прочитано.
     *
     * saveQuietly(), а не save(): обычное сохранение подняло бы события saving,
     * saved, updating, updated. Тишина здесь и ради производительности,
     * и ради страховки от бесконечной рекурсии в будущем — если кто-нибудь
     * добавит в этот же обсервер метод updated(), который снова читает модель.
     */
    public function retrieved(Notification $notification): void {
        if ($notification->read_at !== null) {
            return;
        }

        // Запоминаем, что в момент показа уведомление было новым: ресурс уже
        // не смог бы это определить — read_at заполнится строкой ниже.
        $notification->wasUnread = true;

        $notification->read_at = now();
        $notification->saveQuietly();
    }
}
```

Свойство `wasUnread` объявляем в модели **как настоящее свойство класса**, а не как атрибут:

```php
class Notification extends Model {
    /**
     * Было ли уведомление непрочитанным в момент выборки.
     *
     * Объявлено свойством класса, а не записано в модель как атрибут:
     * $notification->wasUnread = true у Eloquent ушло бы в setAttribute(),
     * и следующий save() попытался бы записать несуществующую колонку.
     */
    public bool $wasUnread = false;
```

и отдаём его в ресурсе вместо вычисления по `read_at`:

```php
            'is_read' => ! $this->wasUnread,
```

Регистрация — тем же атрибутом:

```php
#[ObservedBy(NotificationObserver::class)]
class Notification extends Model {
```

**Честная цена варианта В.** Её надо знать до того, как код попадёт в проект, — и она выше, чем кажется:

1. **Чтение превращается в запись.** Не «в контроллере», а **везде**: консольная команда, отчёт, отладочный `Notification::find(7)` в tinker, `dd($profile->notifications)` — каждое из них молча меняет данные. Отладка перестаёт быть безопасной: посмотрели на строку — испортили состояние.
2. **`GET` меняет состояние**, как и в варианте Б, — со всеми последствиями про префетч и повторы запроса.
3. **Один `UPDATE` на каждую строку.** Двадцать уведомлений в попапе — двадцать запросов вместо одного.
4. **Ловушка с аксессором.** Именно поэтому в разделе 8 счётчик считает через `$this->notifications()->count()`: этот вызов считает в базе и **моделей не создаёт**, значит `retrieved` не поднимает. Напишите там `$this->notifications->count()` — и счётчик начнёт обнулять сам себя при открытии любой страницы. Оба места по отдельности выглядят правильными, и ошибка ищется долго.
5. **Тесты перестают проверять то, что думают.** `$notification->fresh()` или `Notification::find($id)` внутри теста поднимают `retrieved` и сами проставляют `read_at` — проверка «оно не прочитано» пройдёт зелёной на сломанном коде. Поэтому в разделе 19 состояние проверяется `assertDatabaseHas()`, а не через модель.

**Что с этим делать.**

Вариант В — **не нормальная реализация, а учебный эксперимент**: он существует затем, чтобы показать событие `retrieved` и цену «невидимых» побочных эффектов. В рабочем коде так не делают, и называть это «удобным решением» было бы обманом.

Задание просит обсервер — значит его и пишем: это ровно тот опыт, ради которого задание дано. Но:

- в конспекте и в коде **пометьте комментарием**, что это эксперимент и почему;
- если проект пойдёт дальше уроков, замените его на вариант А — маршрут `PATCH` и один `UPDATE` (код в начале этого раздела, вызов из попапа — в разделе 15);
- вариант Б берите, если не хочется заводить второй маршрут и вы готовы согласиться на «`GET` с побочным эффектом».

Мой выбор, если бы ограничения задания не было: **А**. Он единственный не удивляет — ни следующего разработчика, ни браузер, ни тест.

## 19. Тесты

```
php artisan make:test ClientNotificationTest --phpunit
```

Проверяем: уведомление появляется на каждый из трёх поводов, лишнего не появляется (своё действие, повторный лайк), а список отдаёт только своё и помечает показанное прочитанным.

```php
<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientNotificationTest extends TestCase {
    use RefreshDatabase;

    public function test_comment_creates_notification_for_post_author(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Отличная статья',
            ])
            ->assertCreated();

        // Проверяем не «есть хоть какая-то строка», а адресата и источник:
        // перепутать profile_id получателя с author_id комментария — самая
        // вероятная ошибка в этом коде.
        $this->assertDatabaseHas('app_notifications', [
            'profile_id' => $post->author_id,
            'notificationable_type' => Comment::class,
        ]);
    }

    public function test_own_comment_does_not_create_notification(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        $this->actingAs($post->author->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Сам себе отвечу',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_repost_creates_notification_for_original_author(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.reposts.store', $post), [
                'title' => 'Смотрите, что нашёл',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('app_notifications', [
            'profile_id' => $post->author_id,
            'notificationable_type' => Post::class,
        ]);
    }

    public function test_like_creates_notification_for_post_author(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.likes.toggle', $post))
            ->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'profile_id' => $post->author_id,
            'actor_id' => $profile->id,
            'notificationable_type' => Post::class,
            'notificationable_id' => $post->id,
        ]);
    }

    /**
     * Лайк снимают и ставят заново — уведомление должно остаться одно.
     * Это и есть проверка firstOrCreate в LikeObserver: без него после трёх
     * кликов автор получил бы два одинаковых сообщения.
     */
    public function test_repeated_like_does_not_duplicate_notification(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.likes.toggle', $post))   // поставил
            ->assertOk();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.likes.toggle', $post))   // снял
            ->assertOk();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.likes.toggle', $post))   // поставил снова
            ->assertOk();

        $this->assertDatabaseCount('app_notifications', 1);
    }

    public function test_like_on_comment_notifies_its_author(): void {
        $comment = Comment::factory()->create(['status' => Comment::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.comments.likes.toggle', $comment))
            ->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'profile_id' => $comment->author_id,
            'notificationable_type' => Comment::class,
            'notificationable_id' => $comment->id,
        ]);
    }

    public function test_own_like_does_not_create_notification(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        $this->actingAs($post->author->user)
            ->postJson(route('client.posts.likes.toggle', $post))
            ->assertOk();

        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_index_returns_own_notifications_and_marks_them_read(): void {
        $profile = Profile::factory()->create();
        $stranger = Profile::factory()->create();

        $mine = Notification::factory()->create(['profile_id' => $profile->id]);
        $foreign = Notification::factory()->create(['profile_id' => $stranger->id]);

        $this->actingAs($profile->user)
            ->getJson(route('client.profiles.notifications.index'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $mine->id]);

        // Состояние проверяем запросом к таблице, а НЕ через $mine->fresh().
        // fresh() достаёт модель из базы, то есть поднимает событие retrieved —
        // и обсервер из ДЗ-2 прямо в момент проверки проставил бы read_at.
        // Тест стал бы зелёным независимо от того, работает код или нет.
        $this->assertDatabaseMissing('app_notifications', [
            'id' => $mine->id,
            'read_at' => null,
        ]);

        // Чужое не тронуто: его из базы никто не доставал.
        $this->assertDatabaseHas('app_notifications', [
            'id' => $foreign->id,
            'read_at' => null,
        ]);
    }
}
```

Последний тест проверяет сразу две вещи (чужого не видно + пометка прочитанного), и это осознанно: оба утверждения — про один и тот же запрос.

**Про фабрики в тестах на лайки.** `Comment::factory()->create()` сам поднимает `CommentObserver` и заводит уведомление автору поста — то есть ещё до лайка в таблице уже лежит строка. Поэтому в тесте про лайк комментария стоит `assertDatabaseHas` с конкретным `notificationable_type`, а не `assertDatabaseCount`: счёт строк там дал бы двойку, и тест пришлось бы «подгонять» под неочевидное число. Считать строки можно только там, где источник — `Post::factory()`: обычный пост обсервер не трогает.

**Главное правило тестов в этом уроке:** состояние `read_at` проверяется только через базу (`assertDatabaseHas` / `assertDatabaseMissing`), никогда — через модель. Любая гидратация (`fresh()`, `find()`, `refresh()`, обращение к связи) поднимает `retrieved`, а с обсервером из ДЗ-2 это означает, что тест сам себе и проставит проверяемое значение. Такой тест не падает никогда — и не проверяет ничего.

**Чего в этих тестах нет и почему.** `Queue::fake()` не нужен: уведомление пишется прямой вставкой, очередь тут ни при чём. А вот `ClientCommentTest` и `ClientRepostTest` после этого урока стоит перепроверить — в базе теперь появляется лишняя запись, и тест, считающий строки в других таблицах, этого не заметит, а тест с `assertDatabaseCount('app_notifications', ...)` — заметит.

## 20. Проверка

1. Создать модель и миграцию (две команды из раздела 1), заполнить миграцию, `php artisan migrate` — таблица `app_notifications` создана; `php artisan db:show --counts` показывает её с нулём строк, а таблицы `notifications` в списке нет вовсе.
2. В tinker создать уведомление вручную (раздел 7) — строка появилась, `read_at` = `NULL`.
3. Открыть `/feed` — в шапке колокольчик с единицей.
4. `php artisan tinker --execute 'dump(App\Models\Profile::first()->notifications_count);'` — число совпадает с тем, что на экране.
5. В браузере посмотреть исходный ответ Inertia (DevTools → первый запрос → `data-page`): в `props.auth.user` больше нет ни `password`, ни `remember_token`, зато есть `profile.notifications_count`.
6. Клик по колокольчику — попап открылся, в нём строка уведомления со ссылкой.
7. Перейти по ссылке — открылся нужный пост.
8. После реализации ДЗ-2: счётчик уменьшился на число показанных строк, в базе у них заполнен `read_at`. Именно уменьшился, а не обнулился: если непрочитанных было больше двадцати, остаток остаётся на колокольчике — так и задумано (раздел 18).
9. Там же: создать двадцать пять уведомлений (`Notification::factory()->count(25)->create(['profile_id' => ...])`), открыть попап — на колокольчике остаётся 5.
10. Открыть страницу чужого поста другим пользователем и оставить комментарий — у автора поста число выросло без перезагрузки его страницы? Нет, и это правильно: счётчик обновится при следующем переходе. Живое обновление — это broadcasting, его в задании нет.
11. Прокомментировать свой пост — новых уведомлений не появилось.
12. Сделать репост чужого поста — у автора оригинала появилось уведомление с `notificationable_type = App\Models\Post`.
13. Лайкнуть чужой пост — уведомление появилось. Снять лайк и поставить снова — второго уведомления **не** появилось (пока первое не прочитано).
14. Лайкнуть чужой комментарий — уведомление с `notificationable_type = App\Models\Comment`, ссылка ведёт на пост этого комментария.
15. Лайкнуть свой пост — тишина.
16. `php artisan db:seed` после `migrate:fresh` — сидер лайков наполнит и таблицу уведомлений (это ожидаемо, см. грабли); проверьте, что команда не падает.
17. `php artisan test --filter=ClientNotificationTest` — восемь тестов зелёные.
18. `php artisan test` — весь набор зелёный (обсерверы могли задеть тесты комментариев, репостов и лайков, а `->using(Like::class)` — тесты статистики).
19. `vendor/bin/pint --dirty` — правок стиля нет.
20. `composer run dev`, пройти путь целиком: лайк → колокольчик → попап → переход к посту.

## 21. Грабли

- **`Class "App\Models\Notification" not found`, хотя файл есть** — в файле, где вы его используете, подключён `Illuminate\Notifications\Notification` (его любит подставлять IDE). Проверьте секцию `use`.
- **`relation "notifications" does not exist`** — в модели забыт `protected $table = 'app_notifications';`. По конвенции Eloquent ищет таблицу по имени класса, а наша названа иначе; сообщение приходит от PostgreSQL и про модель ничего не говорит.
- **`assertDatabaseHas('notifications', ...)` не находит строк, хотя они есть** — в тестах имя таблицы пишется руками, и подставить конвенционное проще простого. Проверять надо `app_notifications`.
- **Уведомление приходит автору комментария, а не автору поста** — перепутаны `profile_id` получателя и `author_id` источника. Самая частая ошибка этого урока; тест из раздела 19 её ловит.
- **`notificationable_type` пустой** — уведомление создано через `Notification::create([...])` вместо `$comment->notifications()->create([...])`. Полиморфную пару заполняет связь, а не модель.
- **`Field 'body' doesn't have a default value` / «нет значения»** — поле не попало в `$fillable`, и массовое присвоение его отбросило. Молча: ошибка приходит уже от базы.
- **Счётчик всегда ноль, хотя строки в базе есть** — аксессор считает `whereNull('read_at')`, а обсервер `retrieved` пометил всё прочитанным ещё при первом же обращении к списку. Смотрите `read_at` в таблице.
- **Счётчик обнуляется сам, без открытия попапа** — в аксессоре написано `$this->notifications->count()` (без скобок). Коллекция достаёт модели из базы, `retrieved` срабатывает на каждую, и обсервер их читает. Нужно `$this->notifications()->count()`.
- **Счётчик показывает всё вместо непрочитанных** — где-то вызван `withCount('notifications')` без условия `whereNull('read_at')`. Аксессор примет загруженное число как своё и пересчитывать не станет — ошибка молчаливая, без исключений и лишних запросов.
- **Тест «уведомление не прочитано» зелёный на сломанном коде** — проверка идёт через модель (`$notification->fresh()->read_at`), а гидратация поднимает `retrieved`, и обсервер сам проставляет `read_at`. Проверять только `assertDatabaseHas('app_notifications', ['id' => ..., 'read_at' => null])`.
- **`Notification::factory()->create()` создаёт две строки** — источником по умолчанию поставлен `Comment::factory()`, а `CommentObserver` на созданный комментарий заводит своё уведомление. Источник по умолчанию должен быть `Post::factory()`.
- **Уведомления «прочитались» сами, хотя попап не открывали** — пометка живёт внутри `GET`-запроса (варианты Б и В из раздела 18), а `GET` браузер может выполнить сам: префетч ссылки, повтор из истории, восстановление вкладки.
- **`notifications_count` не приезжает во Vue** — обращение идёт к `auth.user.notifications_count` вместо `auth.user.profile.notifications_count`. Посмотрите `data-page` в исходном коде страницы: там видно фактическую форму пропа.
- **`Cannot read properties of null (reading 'notifications_count')`** — у пользователя нет профиля. В `AuthUserResource` нужен тернарник, во Vue — `profile?.notifications_count`.
- **Форма настроек аккаунта приехала с пустой почтой** — из `auth.user` пропал ключ `email`. `UpdateProfileInformationForm.vue` читает `user.email` и `user.email_verified_at` из того же общего пропа.
- **На странице логина белый экран или ошибка в консоли** — `AuthUserResource::make(null)` вместо `null`. Гость есть в приложении всегда, и `share()` обязан это учитывать.
- **В ленте стало на тридцать запросов больше** — счётчик добавили в общий `ProfileResource`, и теперь он считается для каждого автора в списке. Для этого и заведён отдельный ресурс.
- **После сидинга база полна уведомлений** — обсерверы срабатывают и в сидерах. Это не поломка (данные для проверки даже удобны), но если мешает — оберните вызов в `Model::withoutEvents(fn () => $this->call(CommentSeeder::class))` или создавайте записи фабрикой без событий.
- **Чужие тесты покраснели после добавления обсерверов** — там, где тест считал строки или проверял число запросов, добавилась вставка уведомления. Правьте ожидания, а не отключайте обсервер.
- **`SQLSTATE ... column "wasUnread" does not exist`** — свойство `wasUnread` не объявлено в классе модели, и Eloquent принял его за атрибут. Оно должно быть `public bool $wasUnread = false;` в теле класса.
- **Попап открывается пустым и мигает** — `notifications` не очищается между открытиями, а `isLoading` не выставлен. Либо наоборот: список грузится, но `v-else-if="!notifications.length"` стоит раньше проверки загрузки.
- **Попап остаётся висеть после перехода по ссылке** — раскладка при Inertia-переходе не пересоздаётся. Закрывайте его сами в обработчике клика.
- **Число в шапке не меняется после прочтения** — забыт `router.reload({ only: ['auth'] })`. Общие пропсы приезжают со страницей и сами себя не обновляют.
- **`route is not defined` во Vue** — функция `route()` от Ziggy доступна глобально в шаблоне, но в `<script>` внутри стрелочной функции обработчика её видно только как глобальную; проверьте, что не переименовали переменную и что Ziggy подключён в `app.js`.
- **Уведомления не приходят из API-контроллеров** — приходят: обсервер срабатывает на модели, а не на маршруте. Если не приходят, значит запись создаётся не через Eloquent (`DB::table()->insert()` события не поднимает).
- **Лайк не порождает уведомления, хотя `LikeObserver` написан** — в связи забыт `->using(Like::class)`. Без него `attach()` пишет строку прямым запросом, никакой модели не создаётся, и подписываться не на что. Проверять надо ту связь, через которую идёт `toggle()`: `Post::likedByProfiles()` и `Comment::likedByProfiles()`.
- **`SQLSTATE ... column "id" ...` или странное поведение при снятии лайка** — в `Like` не выставлен `public $incrementing = true`. Pivot по умолчанию считается без автоинкрементного ключа, а у `likeables` он есть.
- **После `db:seed` таблица уведомлений забита** — `LikeSeeder` раздаёт лайки пачками, и теперь каждый из них проходит через модель и обсервер. Побочно сидинг стал медленнее: `attach()` с `->using()` делает отдельный `INSERT` на строку вместо одного пакетного. Если мешает — оберните вызов сидера в `Model::withoutEvents(...)`.
- **Автор получает два уведомления об одном лайке** — потерян `firstOrCreate` либо в его условиях нет `'read_at' => null`. Снять и поставить лайк — это два события `created`, и без дедупликации оба дойдут до адресата.
- **Уведомление о лайке изображения падает с «Attempt to read property author_id on null»** — забыта проверка типа в `LikeObserver`: у `Image` автора-профиля нет, лайкать её можно, уведомлять некого.
- **Лайк и репост выглядят одинаково в списке** — так и есть: у обоих `notificationable_type = App\Models\Post`, и различает их только текст `body`. Если нужен фильтр по типу события, в таблицу придётся добавить колонку `type`.

## 22. Что можно сделать лучше

**Дорога для встроенных уведомлений свободна.** Имя `notifications` мы не заняли, поэтому если однажды понадобится фреймворковый механизм (например, ради канала `broadcast`), он встанет рядом без переделок: `php artisan make:notifications-table`, и две системы живут каждая в своей таблице. Это не задача на будущее, а уже полученный запас — просто знайте, что он есть.

**`cascadeOnDelete` на `profile_id` и на источнике.** Уведомления — производные данные: удалили профиль или пост, уведомления о них незачем хранить. Внешний ключ по профилю это умеет (`->constrained('profiles')->cascadeOnDelete()`), а для полиморфного источника каскада не существует в принципе — там нужна чистка кодом (в `PostService::destroy()` или в `deleting`-обсервере).

**Индекс под «непрочитанные».** Запрос `where profile_id = ? and read_at is null` выполняется на каждой странице. Составной индекс `$table->index(['profile_id', 'read_at'])` — ровно под него; отдельный индекс по `profile_id` тогда становится избыточен.

**Пагинация и «показать все».** Сейчас попап отдаёт двадцать последних без возможности посмотреть остальное. Отдельная страница `/notifications` с пагинацией — естественное продолжение, и там же появится повод завести `Client\NotificationController`.

**Группировка одинаковых.** «Пять человек прокомментировали вашу публикацию» вместо пяти строк подряд. Делается запросом с `groupBy` по источнику либо отдельной колонкой-счётчиком у уведомления.

**Живое обновление.** Чтобы число росло без перезагрузки, нужен broadcasting: Laravel Echo + Reverb/Pusher, приватный канал на профиль. Это и есть тот случай, ради которого во встроенных уведомлениях существует канал `broadcast`.

**Очистка старых.** Прочитанные уведомления старше месяца никому не нужны. Команда `notifications:prune` в расписании рядом с `statistics:aggregate` — прямое применение 29-го урока.

**Уведомления о подписках.** Последний повод, который напрашивается: «на вас подписались». Трудность та же, что была у лайков, — подписка тоже живёт в pivot-таблице (`profile_subscriptions`), — но рецепт теперь известен: модель поверх pivot плюс `->using()` в связях `subscribers()` / `subscriptions()`. Получатель — тот, на кого подписались; источник — профиль подписчика, и `NotificationResource::buildUrl()` придётся научить третьему типу (ссылка на `client.profiles.show`).

**Колонка `type` у уведомления.** Лайк и репост сейчас различимы только по тексту `body`. Строковая колонка (`comment`, `repost`, `like`) дала бы фильтрацию, разные иконки в попапе и возможность собирать текст на клиенте вместо хранения готового.

**Показывать инициатора.** `actor_id` в таблице уже есть, но наружу не отдаётся: он работает только на дедупликацию лайков. Связь в `->with()` и ключ в ресурсе превратят «Новый лайк публикации» в «Иван оценил вашу публикацию» — с ником и аватаркой.

## Homework

Сделать оповещения.

- `php artisan make:notifications-table` — не использовать.
- Использовать свою модель: `php artisan make:model Notification -m`.

1. Сделать оповещения пользователя о репостах и комментариях его постов: добавить иконку колокольчика в шапку, рядом с ней выводить количество непрочитанных уведомлений. При клике — подгружать данные и показывать выпадающее меню с уведомлениями со ссылками на соответствующие посты.
2. Продумать и реализовать вариант обновлений оповещений в обсервере (`read_at`).

**Сверх задания курса** (наше решение, не требование преподавателя): уведомлять ещё и о **лайках** — постов и комментариев. Именно этот пункт заставил завести модель `Like` над pivot-таблицей `likeables` (раздел 17) и колонку `actor_id` в таблице уведомлений (раздел 4): без них у лайка нет ни события, на которое подписаться, ни способа отличить повторный лайк от нового.
