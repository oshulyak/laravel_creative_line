# Lesson 28 - Письмо автору поста и подписка на профиль

Цель урока: два независимых куска функциональности, которые объединяет одно — приложение начинает разговаривать за пределами страницы. Первый: когда кто-то комментирует публикацию, её автор получает письмо на почту. Второй: у профиля появляется собственная страница с кнопкой «Подписаться», а клик по нику автора в карточке поста наконец куда-то ведёт.

Письмо в этом уроке отправляется **синхронно**: запрос ждёт SMTP-сервер. Это осознанный шаг — сначала нужно увидеть проблему своими глазами. Очереди, которые её решают, разбираются в 29-м уроке, и там же отправка переедет в задачу.

По пути разбираются: **Mailable** — класс письма с конвертом, содержимым и Blade-шаблоном; `Mail::fake()` и `assertSent()` в тестах; **самоссылающаяся связь многие-ко-многим** (`profiles` ↔ `profiles` через `profile_subscriptions`) и все четыре аргумента `belongsToMany()`, которые в таком случае приходится писать руками; `toggle()` на ней — тот же приём, что с лайками; и на клиенте — страница чужого профиля с кнопкой, которая работает через axios и не перезагружает страницу.

Отправная точка — состояние после 27-го урока.

## 1. Карта урока

| Файл | Что меняется |
| --- | --- |
| `app/Mail/Comment/StoreCommentMail.php` | новый: класс письма |
| `resources/views/mail/comment/store.blade.php` | новый: шаблон письма |
| `app/Http/Controllers/Client/CommentController.php` | отправка письма в `store()` |
| `tests/Feature/ClientCommentMailTest.php` | новый: три теста про письмо |
| `database/migrations/..._create_profile_subscriptions_table.php` | новая: таблица подписок |
| `app/Models/Profile.php` | связи `subscribers()` и `subscriptions()` |
| `routes/client.php` | маршруты `client.profiles.show` и `client.profiles.subscribers.toggle` |
| `app/Http/Controllers/Client/ProfileController.php` | методы `show()` и `toggleSubscribe()` |
| `app/Http/Resources/Profile/ProfileResource.php` | ключи `is_subscribed` и `can_subscribe` |
| `resources/js/Pages/Client/Profile/Show.vue` | новый: страница чужого профиля |
| `resources/js/Components/Post/ItemPost.vue` | ник автора становится ссылкой на профиль |
| `tests/Feature/ClientSubscriptionTest.php` | новый: четыре теста про подписку |

Команды для генерации файлов (запускаете вы):

```
php artisan make:mail Comment/StoreCommentMail --no-interaction
php artisan make:view mail/comment/store --no-interaction
php artisan make:migration create_profile_subscriptions_table --no-interaction
php artisan make:test --phpunit ClientCommentMailTest --no-interaction
php artisan make:test --phpunit ClientSubscriptionTest --no-interaction
```

`make:mail Comment/StoreCommentMail` кладёт класс в `app/Mail/Comment/` с неймспейсом `App\Mail\Comment` — папка внутри `Mail` работает так же, как `Client/Repost` внутри `Requests`. `make:view mail/comment/store` создаёт пустой `resources/views/mail/comment/store.blade.php`; слэши в имени — это подпапки, а в коде тот же путь пишется через точки: `mail.comment.store`.

Ничего для очередей в этом уроке настраивать не нужно: письмо уходит прямо в момент запроса, обычным `Mail::to(...)->send(...)`.

Vue-компонент `Show.vue` создаётся руками: генератора для него в Laravel нет.

---

# Часть I. Письмо автору поста

## 2. Что вообще происходит при отправке письма

В Laravel письмо — это не строка и не вызов `mail()`. Это **объект-класс**, и у него три части:

1. **Envelope** — «конверт»: тема, отправитель, получатели, копии. Кому письмо уйдёт, здесь обычно не указывают — адресата задают снаружи, через `Mail::to(...)`.
2. **Content** — содержимое: какой Blade-шаблон рендерить и с какими данными.
3. **Attachments** — вложения. У нас их нет.

Отправляет письмо фасад `Mail`, а *как* оно физически уедет, решает конфиг `config/mail.php` и переменная `MAIL_MAILER` в `.env`. В проекте сейчас:

```
MAIL_MAILER=smtp
MAIL_HOST=connect.smtp.bz
MAIL_PORT=2525
MAIL_FROM_ADDRESS="hello@spaceprivate.ru"
```

То есть письма уходят через настоящий внешний SMTP-сервер. Это важная деталь: приложение ходит по сети к чужому серверу прямо во время обработки запроса «добавить комментарий», и пользователь всё это время ждёт. Сейчас мы это терпим осознанно — чтобы в 29-м уроке было с чем сравнивать.

Полезные значения `MAIL_MAILER` для разработки:

- `log` — письмо не отправляется, а целиком пишется в `storage/logs/laravel.log`. Идеально, чтобы посмотреть вёрстку, ничего никому не отправив.
- `array` — письмо никуда не идёт и складывается в память. Так работают тесты.
- `smtp` — настоящая отправка.

## 3. `StoreCommentMail`: класс письма

В `app/Mail/Comment/StoreCommentMail.php` (заготовку создаст `make:mail`):

```php
<?php

namespace App\Mail\Comment;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо автору публикации: «вашу публикацию прокомментировали».
 *
 * Никаких признаков очереди на классе нет: письмо отправляется синхронно,
 * прямо в запросе. Заготовка make:mail предлагает ещё и implements ShouldQueue —
 * этот намёк мы сейчас сознательно игнорируем и вернёмся к нему в 29-м уроке,
 * когда будет чем его объяснить.
 */
class StoreCommentMail extends Mailable {
    /**
     * Оба трейта — из заготовки make:mail, оставляем как есть.
     * Queueable добавляет методы onQueue()/delay(), SerializesModels — правила
     * сериализации моделей. При синхронной отправке они не работают: письмо
     * никуда не сериализуется. Смысл у них появится в 29-м уроке.
     */
    use Queueable, SerializesModels;

    /**
     * Свойства public не случайно: все публичные свойства Mailable автоматически
     * попадают в Blade-шаблон под своими именами — в store.blade.php сразу доступны
     * $post и $comment, ничего передавать руками не нужно.
     *
     * Альтернатива (она же вариант из урока) — сделать свойства protected
     * и перечислить данные явно в Content(with: [...]). Так делают, когда в шаблон
     * нужно отдать не модель целиком, а подготовленные значения.
     */
    public function __construct(
        public Post $post,
        public Comment $comment,
    ) {}

    /**
     * Конверт. Указываем только тему: отправителя берём из MAIL_FROM_ADDRESS,
     * получателя задаёт Mail::to() в контроллере.
     *
     * Тема — обычная строка, а не заголовок поста: тема письма должна быть
     * понятна в списке входящих, и «Новый комментарий…» читается лучше,
     * чем случайное название чужой публикации.
     */
    public function envelope(): Envelope {
        return new Envelope(
            subject: 'Новый комментарий к вашей публикации',
        );
    }

    /**
     * Содержимое: имя Blade-шаблона через точки, как в любом view().
     * mail.comment.store → resources/views/mail/comment/store.blade.php.
     */
    public function content(): Content {
        return new Content(
            view: 'mail.comment.store',
        );
    }
}
```

Метод `attachments()` из заготовки удаляем: вложений у письма нет, а пустой метод — это шум.

## 4. Шаблон письма

В `resources/views/mail/comment/store.blade.php`:

```blade
{{--
    Обычный Blade, только результат уезжает не в браузер, а в тело письма.

    Стили пишем инлайном, в атрибуте style. Это не небрежность: почтовые клиенты
    (особенно Gmail и Outlook) вырезают <style> из <head> и внешние файлы —
    инлайновые стили остаются единственным надёжным способом что-то оформить.
--}}
<div style="font-family: Arial, sans-serif; font-size: 14px; color: #1f2937">
    <p>Здравствуйте, {{ $post->author->nickname }}!</p>

    <p>
        {{ $comment->author->nickname }} прокомментировал(а) вашу публикацию
        «{{ $post->title }}»:
    </p>

    {{--
        {{ }} экранирует HTML — и здесь это обязательно, а не по привычке:
        содержимое комментария пишет пользователь, и {!! !!} превратил бы
        письмо в открытую дверь для чужой разметки.
    --}}
    <div style="margin: 12px 0; padding: 10px; background: #f3f4f6; border-left: 4px solid #0369a1">
        {{ $comment->content }}
    </div>

    <p>
        {{--
            route() отдаёт АБСОЛЮТНЫЙ адрес — и в письме он обязан быть таким:
            относительная ссылка в почтовом клиенте никуда не ведёт.

            Домен берётся из APP_URL (у нас http://line.test), а не из текущего
            запроса. Для письма это единственный надёжный источник: адрес должен
            быть одинаковым, откуда бы письмо ни отправлялось.
        --}}
        <a href="{{ route('client.posts.show', $post) }}" style="color: #0369a1">
            Открыть публикацию
        </a>
    </p>
</div>
```

`$post->author` и `$comment->author` — это ленивые связи: на каждую шаблон сходит в базу. Два запроса на одно письмо терпимо, но заметьте: сейчас они происходят внутри запроса пользователя, то есть добавляются к его ожиданию. В шаблоне страницы такое поведение стоило бы `with()`.

## 5. Отправка из контроллера

В `app/Http/Controllers/Client/CommentController.php` — метод `store()`, между созданием комментария и ответом:

```php
    public function store(StoreRequest $request, Post $post): JsonResponse {
        $this->abortUnlessPostVisible($request, $post);

        $comment = $post->comments()->create($request->validated());

        $comment->load('author');

        // Уведомление автору публикации.
        //
        // Условие — «комментатор и автор не один человек»: писать себе о собственном
        // комментарии незачем, а на странице поста автор комментирует свой пост чаще
        // всех остальных вместе взятых.
        //
        // Mail::to() ждёт объект с полями email и name — это User, а не Profile:
        // почта лежит в users, у профиля её нет вовсе. Отсюда цепочка author->user.
        //
        // send() — отправка прямо здесь и сейчас: строка вернёт управление только
        // после того, как SMTP-сервер примет письмо. Это и есть цена синхронной
        // отправки, которую мы разбираем в п. 6.
        if ($post->author_id !== $comment->author_id) {
            Mail::to($post->author->user)->send(new StoreCommentMail($post, $comment));
        }

        return response()->json(CommentResource::make($comment)->resolve(), 201);
    }
```

Добавляются два импорта:

```php
use App\Mail\Comment\StoreCommentMail;
use Illuminate\Support\Facades\Mail;
```

Почему отправка живёт прямо в контроллере, а не в событии или слушателе: пока это одна строка с одним условием. Событие `CommentCreated` + слушатель понадобятся, когда на это же действие повесят второе-третье следствие (уведомление в интерфейсе, счётчик, запись в ленту активности). Заводить их ради одного письма — усложнение без выгоды. Место, где это изменится, отмечено в п. 17.

`storeReply()` письмо не шлёт: задание говорит про комментарий к посту. «Вам ответили» — отдельная и вполне логичная задача, но она не сформулирована, и делать её на всякий случай не нужно.

## 6. Цена синхронной отправки

Строка `Mail::to(...)->send(...)` работает так: приложение открывает TCP-соединение с `connect.smtp.bz`, здоровается, авторизуется, передаёт письмо, ждёт подтверждения — и только потом отдаёт браузеру `201 Created`. Пользователь всё это время смотрит на кнопку «Отправляю…». На локальной машине это секунда-две; на плохом канале или при медленном SMTP — заметно больше.

Хуже другое. Если SMTP-сервер недоступен, запрос падает с 500-й — и комментарий, который **уже записан в базу**, на клиенте выглядит как неудача. Пользователь нажимает «Отправить» ещё раз и получает дубль.

Смотреть на это нужно так: у запроса «добавить комментарий» есть главный сценарий (комментарий должен появиться) и сопутствующий (автора неплохо бы уведомить). Сейчас сопутствующий сценарий держит главный в заложниках: он и задерживает ответ, и может его уронить.

Правильное решение — вынести отправку из запроса: пусть ответ уходит сразу, а письмо отправит отдельный фоновый процесс. Это и есть очереди, и они разбираются в 29-м уроке — там код этого урока получит ровно одну правку. Пока же важно один раз честно увидеть проблему: откройте вкладку Network и посмотрите, сколько длится `POST /posts/{id}/comments` с письмом и сколько — без.

## 7. Тесты письма

В `tests/Feature/ClientCommentMailTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Mail\Comment\StoreCommentMail;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientCommentMailTest extends TestCase {
    use RefreshDatabase;

    public function test_post_author_is_notified_about_new_comment(): void {
        // Mail::fake() подменяет почтовый драйвер заглушкой: письма никуда
        // не уходят, но фасад запоминает всё, что его просили отправить.
        // Без него тест пытался бы достучаться до настоящего SMTP-сервера.
        Mail::fake();

        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Отличная статья',
            ])
            ->assertCreated();

        // assertSent — потому что письмо отправляется синхронно, через send().
        // Есть парный метод assertQueued: он ловит письма, ушедшие в очередь.
        // Перепутать их легко, и ошибка выглядит как «письмо не отправлено»,
        // хотя на деле проверяют не тот список.
        //
        // Замыкание проверяет две вещи разом: адресата (hasTo — помощник самого
        // Mailable) и данные внутри письма. $mail->comment доступен потому,
        // что свойства конструктора объявлены public.
        Mail::assertSent(StoreCommentMail::class, function (StoreCommentMail $mail) use ($post) {
            return $mail->hasTo($post->author->user->email)
                && $mail->comment->content === 'Отличная статья';
        });
    }

    public function test_author_is_not_notified_about_own_comment(): void {
        Mail::fake();

        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);

        // Комментирует сам автор поста.
        $this->actingAs($post->author->user)
            ->postJson(route('client.posts.comments.store', $post), [
                'content' => 'Сам себе отвечу',
            ])
            ->assertCreated();

        Mail::assertNothingSent();
    }

    /**
     * Содержимое письма тестируем отдельно от факта отправки: это разные вопросы,
     * и HTTP-запрос ради проверки вёрстки делать незачем. Mailable рендерится сам,
     * прямо из объекта.
     */
    public function test_mail_shows_post_title_and_comment(): void {
        $post = Post::factory()->create([
            'title' => 'Первый пост',
            'status' => Post::STATUS_PUBLISHED,
        ]);

        $comment = Comment::factory()
            ->for($post, 'commentable')
            ->create([
                'content' => 'Очень интересно',
                'status' => Comment::STATUS_PUBLISHED,
            ]);

        $mailable = new StoreCommentMail($post, $comment);

        $mailable->assertHasSubject('Новый комментарий к вашей публикации');
        $mailable->assertSeeInHtml('Первый пост');
        $mailable->assertSeeInHtml('Очень интересно');
    }
}
```

Заголовок и текст в третьем тесте заданы явно, а не взяты из фабрики: `fake()->sentence()` может вернуть строку с апострофом, Blade экранирует его в `&#039;`, и `assertSeeInHtml()` не найдёт исходную подстроку. Тест упадёт, хотя письмо правильное.

---

# Часть II. Подписка на профиль

## 8. Таблица подписок: многие-ко-многим внутри одной таблицы

Подписка — это связь профиля с профилем: один подписчик может читать многих, и на одного автора подписаны многие. Классическое **многие ко многим**, только обе стороны — одна и та же таблица `profiles`. Такое соединение называют самоссылающимся, и в базе оно ничем не примечательно: обычная промежуточная таблица с двумя внешними ключами, просто оба смотрят в `profiles`.

Имя таблицы. Обычная конвенция Laravel — «два имени моделей в единственном числе по алфавиту» (`role_user`) — здесь не работает: обе модели одинаковые, и получилось бы `profile_profile`. Поэтому имя выбирается по смыслу: **`profile_subscriptions`**. В уроке таблица называется `subscriber_subscribing_create` — похоже на случайно приклеившееся слово из имени миграции; повторять это незачем.

В новом файле миграции:

```php
    public function up(): void {
        Schema::create('profile_subscriptions', function (Blueprint $table) {
            $table->id();
            // Кто подписался.
            //
            // constrained('profiles') с явным именем таблицы: из subscriber_id
            // Laravel вывел бы таблицу subscribers, которой не существует.
            // Ровно та же причина, что у parent_id в прошлом уроке.
            $table->foreignId('subscriber_id')->index()->constrained('profiles');
            // На кого подписался.
            $table->foreignId('subscribing_id')->index()->constrained('profiles');
            // Пара уникальна: подписаться на один профиль дважды нельзя.
            // toggle() и так не создаст дубль, но на уровне базы это гарантия,
            // а не соглашение: два одновременных клика в двух вкладках
            // иначе могли бы записать две строки. Тот же приём, что в likeables.
            $table->unique(['subscriber_id', 'subscribing_id']);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('profile_subscriptions');
    }
```

Поведение внешних ключей при удалении (`nullOnDelete`/`cascadeOnDelete`) здесь не настраиваем — оставляем умолчание, как в `likeables` и `role_user`: профили в проекте не удаляются, и заранее описывать сценарий, которого нет, не нужно.

Отдельной модели `Subscription` не заводим: в промежуточной таблице нет ничего, кроме пары ключей и дат. Модель понадобилась бы, будь у подписки собственные атрибуты (например, «уведомлять по почте: да/нет») или собственные экраны.

## 9. Модель: две связи вокруг одной таблицы

В `app/Models/Profile.php` добавляются две связи:

```php
    /**
     * Профили, подписанные на этот профиль («мои подписчики»).
     *
     * Самоссылающаяся связь многие-ко-многим: обе стороны — profiles. Угадать
     * Laravel тут не может ничего, поэтому все четыре аргумента указаны явно:
     *
     * 1. related — какую модель достаём (Profile);
     * 2. table — промежуточная таблица;
     * 3. foreignPivotKey — колонка, в которой лежит id ТЕКУЩЕГО профиля;
     * 4. relatedPivotKey — колонка, в которой лежит id того, кого достаём.
     *
     * Здесь текущий профиль — тот, НА КОГО подписаны, значит его id лежит
     * в subscribing_id, а достаём мы подписчиков из subscriber_id.
     */
    public function subscribers(): BelongsToMany {
        return $this->belongsToMany(
            Profile::class,
            'profile_subscriptions',
            'subscribing_id',
            'subscriber_id',
        )->withTimestamps();
    }

    /**
     * Профили, на которые подписан этот профиль («мои подписки»).
     *
     * Та же таблица, те же две колонки — поменялись местами. Пара
     * subscribers()/subscriptions() — это две стороны одной связи, как
     * parent()/reposts() у поста.
     *
     * withTimestamps() нужен, чтобы attach() заполнял created_at и updated_at:
     * по умолчанию Eloquent промежуточные даты не трогает, и колонки остались бы
     * пустыми. Точно так же настроены likedPosts() и likedComments().
     */
    public function subscriptions(): BelongsToMany {
        return $this->belongsToMany(
            Profile::class,
            'profile_subscriptions',
            'subscriber_id',
            'subscribing_id',
        )->withTimestamps();
    }
```

Импорт `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` добавляется к уже имеющимся.

В уроке связи названы `subscribers()` и `subscribings()`. Второе имя мы заменили на `subscriptions()`: «подписки» — это то, что видит пользователь в интерфейсе, а имя совпадает с именем таблицы. Смысл и SQL при этом те же.

Аксессора `getIsSubscribedAttribute()` из урока у нас не будет — почему, в п. 11.

## 10. Маршруты

В `routes/client.php`, в ту же группу `auth`:

```php
    // Страница чужого профиля. Про этот маршрут мы писали ещё в 23-м уроке,
    // когда заводили profiles/personal, — вот он.
    //
    // Порядок объявления относительно profiles/personal роли не играет:
    // whereNumber() ограничивает сегмент цифрами, и слово personal под этот
    // маршрут не подойдёт никогда.
    Route::get('profiles/{profile}', [ProfileController::class, 'show'])
        ->whereNumber('profile')
        ->name('client.profiles.show');

    // Переключение подписки. Форма ровно та же, что у лайка: POST на «подписчиков»
    // профиля, а глагол toggle живёт в имени маршрута, а не в адресе.
    //
    // Почему один toggle вместо пары store/destroy — по той же причине, что
    // и у лайков: клиент не знает наверняка, подписан ли он прямо сейчас,
    // и выбирать метод по устаревшим данным ему не нужно. Решает сервер.
    Route::post('profiles/{profile}/subscribers', [ProfileController::class, 'toggleSubscribe'])
        ->whereNumber('profile')
        ->name('client.profiles.subscribers.toggle');
```

## 11. Контроллер: `show()` и `toggleSubscribe()`

В `app/Http/Controllers/Client/ProfileController.php`:

```php
    /**
     * Страница чужого профиля: шапка с кнопкой подписки и публикации автора.
     *
     * Profile $profile приходит неявной привязкой модели — несуществующий id
     * даёт 404 до контроллера.
     */
    public function show(Request $request, Profile $profile): Response {
        // Профиль того, КТО смотрит. Может отсутствовать — тогда подписка
        // недоступна, но страницу показать всё равно нужно.
        $viewer = $request->user()->profile;

        // «Подписан ли смотрящий на этот профиль» — тот же приём, что is_liked
        // у постов: подзапрос по связи, суженный до одного профиля.
        //
        // loadExists(), а не withExists(): модель уже получена контейнером,
        // достроить его запрос мы не можем — догружаем отдельным.
        //
        // Псевдоним as is_subscribed задаёт имя атрибута: без него он звался бы
        // subscribers_exists.
        //
        // whereKey(null) при отсутствии профиля даст сравнение с NULL,
        // не истинное ни для одной строки, — кнопка приедет в состоянии
        // «не подписан».
        $profile->loadExists([
            'subscribers as is_subscribed' => fn (Builder $query) => $query->whereKey($viewer?->id),
        ]);

        $posts = $profile->posts()
            // Ключевое отличие от personal(): на ЧУЖОЙ странице показываем только
            // опубликованное. Пост на модерации видит лишь его автор — у себя
            // в «Моих публикациях». То же правило, что в ленте.
            ->where('status', Post::STATUS_PUBLISHED)
            // Набор связей и счётчиков диктует карточка ItemPost: без author
            // в ней будет «Аноним», без parent.author не подпишется репост,
            // без счётчиков — нули под иконками.
            ->with(['author', 'category', 'parent.author'])
            ->withCount(['likedByProfiles', 'reposts'])
            ->withExists([
                'likedByProfiles as is_liked' => fn (Builder $query) => $query->whereKey($viewer?->id),
            ])
            ->latest('id')
            ->paginate(10);

        return inertia('Client/Profile/Show', [
            'profile' => ProfileResource::make($profile)->resolve(),
            'posts' => PostResource::collection($posts)->response()->getData(true),
        ]);
    }

    /**
     * Переключение подписки на профиль.
     *
     * Возвращает массив, а не Inertia-страницу: запрос уходит от axios, страница
     * остаётся на месте, обновить нужно только надпись на кнопке. Полный близнец
     * PostController::toggleLike().
     */
    public function toggleSubscribe(Request $request, Profile $profile): array {
        $viewer = $request->user()->profile;

        // Подписка принадлежит профилю, и без профиля операция невозможна.
        abort_if($viewer === null, 403, 'У пользователя нет профиля.');

        // Подписка на себя запрещена на сервере, а не только скрытием кнопки:
        // can_subscribe в ресурсе — подсказка интерфейсу, POST-запрос руками
        // никто не отменял.
        abort_if($viewer->id === $profile->id, 403, 'Нельзя подписаться на себя.');

        // toggle() на связи: строка в profile_subscriptions есть — удалить, нет —
        // вставить. Возвращает ['attached' => [...], 'detached' => [...]],
        // то есть готовый ответ на вопрос «что в итоге произошло».
        $changes = $viewer->subscriptions()->toggle($profile->id);

        return [
            'is_subscribed' => $changes['attached'] !== [],
        ];
    }
```

Добавляются импорты `use App\Models\Post;` и `use App\Models\Profile;`.

**Почему не аксессор.** В уроке состояние подписки считает аксессор в модели:

```php
public function getIsSubscribedAttribute(): bool {
    return $this->subscribers->contains('id', auth()->user()->profile->id);
}
```

Он работает, но делает две неприятные вещи. Первая: `$this->subscribers` **загружает всех подписчиков профиля в память** — у популярного автора это тысячи строк ради одного булева ответа. `loadExists()` вместо этого отправляет в базу `EXISTS (...)` и возвращает `true`/`false`. Вторая: модель начинает знать про `auth()`, то есть про текущего пользователя. Модель описывает данные, а «кто сейчас смотрит» — это вопрос запроса; в проекте такой признак уже считается в контроллере (`is_liked`), и подписка идёт тем же путём.

## 12. `ProfileResource`: два новых ключа

В `app/Http/Resources/Profile/ProfileResource.php` к существующим полям добавляются:

```php
            // Подписан ли текущий пользователь на этот профиль.
            //
            // whenHas — «отдай ключ, только если такой атрибут у модели есть».
            // Атрибут появляется от loadExists() в ProfileController::show();
            // там, где ресурс отдаёт автора поста внутри карточки, его нет,
            // и ключа в JSON не будет.
            //
            // Приведение к bool: PostgreSQL вернёт настоящий boolean, MySQL отдал бы
            // 1/0 — клиент не должен помнить, чем именно отвечает база.
            'is_subscribed' => $this->whenHas('is_subscribed', fn (mixed $value): bool => (bool) $value),
            // Есть ли смысл показывать кнопку подписки. Правило простое:
            // на собственный профиль подписаться нельзя.
            //
            // Ключ отдаётся всегда, без условий: это дешёвое вычисленное булево,
            // а не связь, ради которой пришлось бы идти в базу, — так же устроен
            // can_delete у поста.
            //
            // Как и can_delete, это подсказка интерфейсу, а не защита: настоящая
            // проверка стоит в toggleSubscribe().
            'can_subscribe' => $this->id !== $request->user()?->profile?->id,
```

## 13. Клиент

### 13.1. `Show.vue` — страница чужого профиля

Новый файл `resources/js/Pages/Client/Profile/Show.vue`:

```vue
<template>
    <Head :title="profile.nickname" />

    <section class="mb-6 flex items-start justify-between gap-4 rounded-lg bg-white p-5 shadow">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ profile.nickname }}</h1>

            <p class="text-sm text-gray-500">{{ fullName }}</p>

            <p class="mt-2 text-sm text-gray-500">Публикаций: {{ posts.meta.total }}</p>
        </div>

        <!--
            v-if по флагу с сервера, а не по сравнению id на клиенте: на собственной
            странице кнопки нет вовсе. Тот же приём, что с can_delete у карточки.

            <button>, а не <a href="#" @click.prevent>: это действие, а не переход.
            Кнопка сама получает фокус с клавиатуры и умеет disabled — ссылке
            всё это пришлось бы имитировать.
        -->
        <button
            v-if="profile.can_subscribe"
            type="button"
            :disabled="isSending"
            class="shrink-0 rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50"
            :class="
                isSubscribed
                    ? 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                    : 'border-sky-700 bg-sky-700 text-white hover:bg-sky-800'
            "
            @click="toggleSubscribe"
        >
            {{ isSubscribed ? 'Отписаться' : 'Подписаться' }}
        </button>
    </section>

    <p v-if="!posts.data.length" class="rounded-lg bg-white p-6 text-sm text-gray-500">
        У этого профиля пока нет публикаций.
    </p>

    <!--
        Та же карточка, что в ленте и в «Моих публикациях». Кнопки удаления
        в ней не будет: посты чужие, и can_delete приедет ложным — решение
        принял сервер, компонент менять не нужно.

        provide() здесь нет намеренно: удалять на этой странице нечего,
        а репост уезжает в «Мои публикации» — перезапрашивать чужой профиль
        после него незачем. Счётчик под иконкой репоста кнопка обновит сама.
    -->
    <ItemPost v-for="post in posts.data" :key="post.id" :post="post" />

    <nav v-if="posts.meta.last_page > 1" class="mt-6 flex flex-wrap gap-1">
        <template v-for="(link, index) in posts.meta.links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
                class="border px-3 py-2 text-sm"
                :class="
                    link.active
                        ? 'border-sky-800 bg-sky-700 text-white'
                        : 'border-gray-300 bg-white hover:bg-gray-50'
                "
                v-html="link.label"
            />
            <span
                v-else
                class="border border-gray-200 px-3 py-2 text-sm text-gray-300"
                v-html="link.label"
            />
        </template>
    </nav>
</template>

<script>
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';
import ItemPost from '@/Components/Post/ItemPost.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link, ItemPost },
    props: {
        profile: {
            type: Object,
            required: true,
        },
        posts: {
            type: Object,
            default: () => ({ data: [], meta: { links: [], last_page: 1, total: 0 } }),
        },
    },
    data() {
        return {
            // Локальная копия: кнопка меняет состояние сразу после ответа сервера,
            // а писать в проп нельзя — поток данных односторонний.
            //
            // Boolean(): ключа is_subscribed может не быть вовсе (whenHas),
            // и undefined в шаблоне дал бы «Подписаться» — верно по смыслу,
            // но полагаться на это не стоит.
            isSubscribed: Boolean(this.profile.is_subscribed),
            isSending: false,
        };
    },
    computed: {
        /**
         * Имя и фамилия, если они заполнены: оба поля в profiles nullable.
         */
        fullName() {
            return (
                [this.profile.first_name, this.profile.second_name]
                    .filter(Boolean)
                    .join(' ') || 'Имя не заполнено'
            );
        },
    },
    methods: {
        /**
         * Отправка через axios, а не через Inertia: меняется одна надпись,
         * перерисовывать страницу и заново тянуть список постов незачем.
         *
         * Состояние берём из ответа, а не переключаем на клиенте: сервер —
         * единственный источник правды, и при отказе (403) ничего не изменится.
         */
        toggleSubscribe() {
            this.isSending = true;

            axios
                .post(route('client.profiles.subscribers.toggle', this.profile.id))
                .then((res) => {
                    this.isSubscribed = res.data.is_subscribed;
                })
                .finally(() => {
                    this.isSending = false;
                });
        },
    },
};
</script>
```

Отдельный компонент `SubscribeButton.vue` не заводим. `LikeButton` вынесли потому, что он понадобился и посту, и комментарию; кнопка подписки пока существует ровно в одном месте. Появится вторая (например, в карточке поста рядом с ником) — тогда и вынесем.

### 13.2. `ItemPost.vue`: ник автора становится ссылкой

Без этого на страницу профиля можно попасть только руками, набрав адрес. В шапке карточки строка с ником и категорией меняется так:

```vue
        <p class="mb-1 text-xs uppercase tracking-wider text-gray-400">
            <!--
                v-if по самому объекту author, а не по постфиксу ?.: ссылке нужен
                ещё и author.id, а строить маршрут с undefined route() не станет —
                Ziggy бросит ошибку и уронит рендер.
            -->
            <Link
                v-if="postData.author"
                :href="route('client.profiles.show', postData.author.id)"
                class="hover:text-sky-700"
            >
                {{ postData.author.nickname }}
            </Link>
            <span v-else>Аноним</span>
            · {{ postData.category?.title ?? 'Без категории' }}
        </p>
```

`Link` уже импортирован в компоненте — он используется для заголовка поста.

## 14. Тесты подписки

В `tests/Feature/ClientSubscriptionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientSubscriptionTest extends TestCase {
    use RefreshDatabase;

    public function test_user_subscribes_and_unsubscribes(): void {
        $subscriber = Profile::factory()->create();
        $author = Profile::factory()->create();

        // Первый клик — подписка.
        $this->actingAs($subscriber->user)
            ->postJson(route('client.profiles.subscribers.toggle', $author))
            ->assertOk()
            ->assertJsonPath('is_subscribed', true);

        $this->assertDatabaseHas('profile_subscriptions', [
            'subscriber_id' => $subscriber->id,
            'subscribing_id' => $author->id,
        ]);

        // Второй клик тем же пользователем — отписка. Проверяем именно пару
        // вызовов: toggle() легко перепутать местами и подписать «наоборот».
        $this->actingAs($subscriber->user)
            ->postJson(route('client.profiles.subscribers.toggle', $author))
            ->assertOk()
            ->assertJsonPath('is_subscribed', false);

        $this->assertDatabaseMissing('profile_subscriptions', [
            'subscriber_id' => $subscriber->id,
            'subscribing_id' => $author->id,
        ]);
    }

    public function test_user_cannot_subscribe_to_himself(): void {
        $profile = Profile::factory()->create();

        $this->actingAs($profile->user)
            ->postJson(route('client.profiles.subscribers.toggle', $profile))
            ->assertForbidden();

        $this->assertDatabaseCount('profile_subscriptions', 0);
    }

    public function test_profile_page_shows_subscription_state(): void {
        $subscriber = Profile::factory()->create();
        $author = Profile::factory()->create();

        // Подписываем напрямую через связь, а не через HTTP: тест проверяет
        // страницу, а не повторяет уже проверенное переключение.
        $subscriber->subscriptions()->attach($author->id);

        $this->actingAs($subscriber->user)
            ->get(route('client.profiles.show', $author))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Profile/Show')
                ->where('profile.is_subscribed', true)
                ->where('profile.can_subscribe', true)
                ->etc());
    }

    public function test_profile_page_hides_posts_on_moderation(): void {
        $author = Profile::factory()->create();

        Post::factory()->for($author, 'author')->create([
            'title' => 'Опубликованный',
            'status' => Post::STATUS_PUBLISHED,
        ]);
        Post::factory()->for($author, 'author')->create([
            'title' => 'На модерации',
            'status' => Post::STATUS_MODERATE,
        ]);

        // Чужой черновик не должен попасть на страницу автора — это правило
        // легко потерять, скопировав выборку из personal(), где фильтра нет.
        $this->actingAs(Profile::factory()->create()->user)
            ->get(route('client.profiles.show', $author))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('posts.data', 1)
                ->where('posts.data.0.title', 'Опубликованный')
                ->etc());
    }
}
```

## 15. Проверка

1. `php artisan migrate` — миграция проходит, появилась таблица `profile_subscriptions`.
2. `php artisan route:list --path=profiles` — три маршрута: `profiles/personal`, `GET profiles/{profile}`, `POST profiles/{profile}/subscribers`.
3. `php artisan test --filter=ClientCommentMailTest` — три теста зелёные.
4. `php artisan test --filter=ClientSubscriptionTest` — четыре теста зелёные.
5. `php artisan test --filter=ClientCommentTest` — старые тесты комментариев не сломались.
6. `vendor/bin/pint --dirty` — правок стиля нет.
7. `composer run dev` запущен, открыт `/feed`.
8. Клик по нику автора в карточке — открылась страница профиля со списком его публикаций.
9. На своей странице (`/profiles/{свой id}`) кнопки «Подписаться» нет.
10. На чужой странице клик по «Подписаться» — надпись сменилась на «Отписаться», страница не перезагрузилась.
11. Повторный клик — снова «Подписаться». F5 — состояние сохранилось (значение приехало с сервера, а не осталось в памяти).
12. Вкладка Network при клике: `POST /profiles/5/subscribers` со статусом 200 и телом `{ "is_subscribed": true }`.
13. Комментарий к чужому посту: письмо пришло на почту автора.
14. Чтобы увидеть само письмо, не отправляя его: в `.env` временно `MAIL_MAILER=log`, затем `php artisan config:clear` и перезапуск `composer run dev` — письмо целиком окажется в `storage/logs/laravel.log`. После проверки вернуть `smtp`.
15. Комментарий к СВОЕМУ посту: письма нет.
16. Вкладка Network: у `POST /posts/{id}/comments` с письмом время ответа заметно больше, чем у комментария к своему посту, где письмо не отправляется. Это та самая задержка, ради которой в 29-м уроке появятся очереди.

## 16. Грабли

- **`Class "App\Mail\StoreCommentMail" not found`** — в `make:mail` потерялась папка: имя должно быть `Comment/StoreCommentMail`, тогда неймспейс станет `App\Mail\Comment`.
- **`View [mail.comment.store] not found`** — шаблон не создан либо создан не там: путь `mail.comment.store` — это `resources/views/mail/comment/store.blade.php`.
- **`Undefined variable $post` в шаблоне письма** — свойства конструктора объявлены `protected`/`private`. В шаблон автоматически попадают только `public`; для остальных нужен `Content(with: [...])`.
- **Письмо уходит на несуществующий адрес / `Attempt to read property "email" on null`** — в `Mail::to()` передали `Profile` вместо `User`: почта лежит в `users`, правильная цепочка — `$post->author->user`.
- **Ответ на «добавить комментарий» стал долгим** — так и должно быть: запрос ждёт SMTP-сервер. Это не ошибка урока, а его финальная точка; лечится очередью в 29-м.
- **`POST /posts/{id}/comments` падает 500-й, но комментарий в базе есть** — SMTP недоступен, и исключение вылетело уже ПОСЛЕ создания комментария. Ещё один довод в пользу очереди. Быстрое временное лечение — `MAIL_MAILER=log`.
- **Тест падает на `Mail::assertQueued()`** — письмо отправляется синхронно, проверять нужно `assertSent()`. И наоборот, если случайно добавили классу `implements ShouldQueue`.
- **Тест пытается отправить настоящее письмо и висит** — забыт `Mail::fake()` в начале теста.
- **В письме относительная ссылка вида `/posts/5`** — вместо `route()` подставлен `url()->current()` или путь руками. В письме адрес обязан быть абсолютным, а его домен берётся из `APP_URL`.
- **Автор получает письмо о собственном комментарии** — забыто условие `$post->author_id !== $comment->author_id`.
- **`SQLSTATE[42P01]: Undefined table: relation "subscribers" does not exist`** — в миграции у `constrained()` не указана таблица: из `subscriber_id` Laravel выводит `subscribers`.
- **Подписка работает «наоборот»: подписался на одного, а подписчик появился у другого** — переставлены местами `foreignPivotKey` и `relatedPivotKey`. Третий аргумент — колонка ТЕКУЩЕЙ модели, четвёртый — колонка той, что достаём.
- **`Call to undefined relationship [subscriptions]`** — связь добавлена в `User`, а не в `Profile`: подписка принадлежит профилю, как и лайк.
- **Кнопка всегда показывает «Подписаться», хотя подписка есть** — в контроллере нет `loadExists()`, и ключа `is_subscribed` в JSON просто нет. Видно во вкладке Network.
- **Состояние кнопки сбрасывается после F5** — то же самое: значение живёт только в `data()` и не приезжает с сервера.
- **Кнопка есть на собственной странице** — не проверяется `profile.can_subscribe`, либо ключ не добавлен в `ProfileResource`.
- **403 при подписке у пользователя без профиля** — это ожидаемое поведение, а не ошибка: подписка принадлежит профилю.
- **На чужой странице видны посты «На модерации»** — выборка скопирована из `personal()` вместе с отсутствующим фильтром по статусу.
- **`Ziggy error: route 'client.profiles.show' is not in the route list`** — маршрут добавлен, но список маршрутов закэширован: помогает `php artisan route:clear`.
- **`Cannot read properties of undefined (reading 'id')` в карточке** — ссылка на профиль построена без проверки `v-if="postData.author"`: связь `author` может быть не загружена.

## 17. Что можно сделать лучше

**Очередь вместо синхронной отправки.** Ближайший шаг и тема следующего урока: отправка уезжает в фоновую задачу, ответ клиенту перестаёт зависеть от SMTP-сервера, а упавшее письмо больше не роняет запрос.

**Событие вместо прямой отправки.** Как только на «добавлен комментарий» повесят второе следствие (уведомление в интерфейсе, счётчик непрочитанного, запись в ленту активности), место для этого — событие `CommentCreated` и слушатели. Контроллер тогда сообщает факт, а кто на него реагирует — уже не его дело.

**Notifications вместо Mailable.** У Laravel есть отдельный слой уведомлений с каналами: одно и то же событие уходит и на почту, и в базу (колокольчик в интерфейсе), и в мессенджер. Mailable — это «письмо», Notification — «уведомление, одна из форм которого письмо». Для второго канала переходить стоит именно туда.

**Markdown-письма.** `make:mail --markdown=mail.comment.store` даёт шаблон с готовыми компонентами (кнопка, панель, подвал) и фирменной вёрсткой, которая корректно выглядит в почтовых клиентах. Наш инлайновый HTML — минимальный вариант, честный для учебного шага, но руками поддерживать его дальше неприятно.

**Настройка «не присылать письма».** Сейчас уведомление безусловно. Взрослый вариант — флаг в профиле и проверка перед отправкой; это же место, где пригодится отдельная модель подписки с атрибутами.

**Уведомления на ответы и репосты.** «Вам ответили» и «вашу публикацию репостнули» — те же две строки в `storeReply()` и `storeRepost()`. Стоит делать сразу через событие, иначе третья копия отправки расползётся по контроллерам.

**Лента по подпискам.** Главное продолжение этого урока: `FeedController` пока показывает все опубликованные посты, и в 23-м уроке про это стоит прямая пометка. Теперь связь есть, и фильтр — это одно условие: `whereIn('author_id', $profile->subscriptions()->pluck('profiles.id'))`. Заодно появится вопрос, что показывать тому, кто ещё ни на кого не подписан.

**Счётчики подписчиков и подписок.** `withCount(['subscribers', 'subscriptions'])` в `show()` и две цифры в шапке профиля. Дальше — страницы «Подписчики» и «Подписки» со списками, по образцу ветки ответов из 26-го урока.

**Профиль в других местах.** Ник автора комментария и подпись «Репост: … · автор» пока не кликабельны — те же ссылки на `client.profiles.show` напрашиваются и там.

**Политики.** Правил «кому что можно» стало ещё больше: видимость поста, удаление поста, репост, теперь подписка. `PostPolicy` и `ProfilePolicy` собрали бы их в одном месте — это ближайший крупный шаг по курсу.
