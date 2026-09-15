# Lesson 33 - Веб-сокеты: сообщения и уведомления в реальном времени

Цель урока: сообщение собеседника появляется в открытом чате без перезагрузки, а число на колокольчике растёт в тот момент, когда кто-то лайкнул, прокомментировал или репостнул вашу запись.

По пути разбираются: **broadcasting** — как Laravel отправляет событие в браузер без запроса с его стороны; **веб-сокет-сервер** и почему он работает отдельно от Laravel; **Pusher** (облачный сервис, как на уроке) и **Reverb** (свой сервер, как в коде проекта); **событие** с `ShouldBroadcastNow`: канал, имя и данные; **приватные каналы** и правила доступа в `routes/channels.php`; **`toOthers()`** и заголовок `X-Socket-ID`; **Laravel Echo** во Vue: подписка, отписка и обновление общего пропса через `router.replaceProp()`.

Отправная точка — состояние после 32-го урока. Сообщение отправляется через axios и сразу встаёт в ленту отправителя. Уведомления создаются обсерверами и считаются на колокольчике. Но собеседник видит новое сообщение, а получатель — новое уведомление только после перезагрузки страницы.

Главная мысль урока: **драйвер broadcasting — это настройка, а не код**. Событие, каналы, правила доступа и подписка во Vue пишутся один раз. Pusher или Reverb — это несколько строк в `.env` и одно слово в `configureEcho()`. Поэтому Pusher разобран в конспекте, в проекте работает Reverb, и код от этого не раздваивается.

Вторая мысль продолжает 32-й урок: **JSON сообщения один для всех, кто его видит**. Теперь это работает на деле. Тот же `MessageResource`, которым сообщение отдают маппер и ответ на отправку, уходит собеседнику через веб-сокет, а `ItemMessage` сам решает, чьё это сообщение.

## 1. Карта урока

| Файл | Что меняется |
| --- | --- |
| `.env` | `BROADCAST_CONNECTION=reverb`, ключи `REVERB_*` и `VITE_REVERB_*` (пишет установщик) |
| `config/broadcasting.php`, `config/reverb.php` | новые: публикует установщик, руками не правим |
| `bootstrap/app.php` | строка `channels:` в `withRouting()` (пишет установщик) |
| `routes/channels.php` | новый: правила доступа к двум приватным каналам |
| `resources/js/app.js` | `configureEcho()` и `window.axios` (руками: установщик этот шаг пропускает, раздел 7) |
| `composer.json` | пакет `laravel/reverb` (отдельной командой, раздел 7), `reverb:start` в скриптах `dev` и `dev:serve` (руками) |
| `composer.lock` | Guzzle 8.1 → 7.15: без этого Reverb не ставится (раздел 7) |
| `package.json` | пакеты `laravel-echo`, `pusher-js`, `@laravel/echo-vue` (установщик) |
| `app/Events/WS/SendMessageEvent.php` | новое событие: сообщение — участникам чата |
| `app/Events/WS/SendNotificationEvent.php` | новое событие: число непрочитанных — получателю |
| `app/Http/Controllers/Client/ChatController.php` | `broadcast(...)->toOthers()` в `storeMessage()` |
| `app/Observers/NotificationObserver.php` | новый метод `created()` |
| `resources/js/Pages/Client/Chat/Show.vue` | подписка на канал чата |
| `resources/js/Layouts/ClientLayout.vue` | подписка на канал уведомлений |
| `tests/Feature/ClientMessageTest.php` | два теста: событие и правило канала |
| `tests/Feature/ClientNotificationTest.php` | два теста: событие и правило канала |

Команды (запускаете вы):

```
php artisan install:broadcasting --reverb
composer require laravel/reverb -w
php artisan reverb:install
php artisan make:event WS/SendMessageEvent
php artisan make:event WS/SendNotificationEvent
```

Перед установкой остановите `composer run dev`: установщик запускает `npm install`, а на Windows работающий Vite может держать файлы в `node_modules`.

`install:broadcasting` по ходу задаст два вопроса: ставить ли Reverb и ставить ли Node-зависимости. На оба отвечаем «да». Команда сама запускает `composer require`, `npm install` и `npm run build`, поэтому работает дольше обычного `make:`. В нашем проекте поставить Reverb у неё не получается (конфликт версий Guzzle), поэтому Reverb ставится второй и третьей командой. Между первой и второй нужно поправить `.env`. Порядок и причины — раздел 7.

`make:event WS/SendMessageEvent` создаст `app/Events/WS/SendMessageEvent.php` с неймспейсом `App\Events\WS`. Папку `WS` (WebSocket) задаёт курс: события, которые уходят в браузер, лежат отдельно от остальных.

Миграций в уроке нет.

---

# Часть I. Как это устроено

## 2. Задача: сервер должен сообщить первым

До сих пор проект работал по схеме «браузер спросил — сервер ответил». Сам сервер ничего сообщить браузеру не может: он ждёт запроса. В 32-м уроке это видно на последнем шаге проверки: собеседник видит ответ только после перезагрузки.

Исправить это можно двумя способами:

| | Опрос (polling) | Веб-сокет |
| --- | --- | --- |
| Как | браузер раз в N секунд спрашивает «есть новое?» | браузер держит открытое соединение, сервер пишет в него сам |
| Задержка | до N секунд | доли секунды |
| Нагрузка | запрос от каждой открытой вкладки раз в N секунд, даже если нового нет | трафик только когда есть событие |
| Что нужно | ничего нового (в Inertia есть `usePoll`) | веб-сокет-сервер и библиотека в браузере |

Для чата задержка в несколько секунд заметна, а опрос раз в секунду от каждой вкладки — лишняя нагрузка. Поэтому веб-сокеты. В Laravel эта возможность называется **broadcasting** — трансляция событий в браузер.

## 3. Как работает веб-сокет

### Соединение, которое не закрывается

Обычный HTTP похож на письмо: браузер отправил запрос, сервер ответил, соединение закрылось. Первым написать браузеру сервер не может, он ждёт следующего запроса.

Веб-сокет похож на телефонный звонок. Соединение открывается один раз и остаётся открытым, а говорить по нему могут обе стороны в любой момент.

Начинается веб-сокет с обычного HTTP-запроса, в котором браузер просит сменить протокол:

```
Браузер → GET /app/k95siab... HTTP/1.1
          Upgrade: websocket                  ← «перейдём на веб-сокет»

Reverb  → HTTP/1.1 101 Switching Protocols    ← «переходим»
```

После ответа `101` HTTP заканчивается. По тому же соединению дальше ходят короткие сообщения — фреймы — в обе стороны. Адрес начинается с `ws://`, а с шифрованием — с `wss://`, так же как `http://` и `https://`.

Всё это видно в браузере: DevTools → Network → вкладка **WS** → соединение с `localhost:8080` → **Messages**.

### Участники

Обычный HTTP-запрос к Laravel завершается сразу после ответа, и держать в нём открытое соединение с браузером негде. Поэтому веб-сокет-соединения обслуживает отдельный долгоживущий процесс — **веб-сокет-сервер**. Это ограничение модели «запрос — ответ», а не PHP: Reverb сам написан на PHP и работает постоянно, как воркер очереди из 29-го урока.

```
                 ┌────────────────────────────┐
   HTTP-запрос   │          Laravel           │
  ─────────────► │ сохранил сообщение         │
                 │ broadcast(new Event(...))  │
                 └─────────────┬──────────────┘
                               │ HTTP: «отправь событие в канал»
                               ▼
                 ┌────────────────────────────┐
                 │     Веб-сокет-сервер       │
                 │     Pusher или Reverb      │
                 └──┬──────────────────────┬──┘
     открытое       │                      │     открытое
     соединение     ▼                      ▼     соединение
              Браузер B              Браузер C
               (Echo)                 (Echo)
```

- **Laravel** решает, *что* и *куда* отправить: создаёт событие и передаёт его веб-сокет-серверу обычным HTTP-запросом. Ещё Laravel решает, кому можно слушать приватный канал (раздел 4).
- **Веб-сокет-сервер** держит открытые соединения с браузерами и раздаёт им события. Это либо **Pusher** — облачный сервис, либо **Reverb** — PHP-пакет от команды Laravel: долгоживущий процесс, который запускается командой `php artisan reverb:start` на вашей машине.
- **Laravel Echo** — JavaScript-библиотека в браузере. Она открывает соединение, подписывается на каналы и вызывает ваши функции, когда приходит событие.

Словарь урока:

| Термин | Что это | Где в коде |
| --- | --- | --- |
| Канал | «адрес», на который подписываются браузеры | `broadcastOn()` в событии, `echo().private('...')` во Vue |
| Имя события | что случилось; по нему браузер выбирает обработчик | `broadcastAs()`, `.listen('.имя', ...)` |
| Данные события | JSON, который получит браузер | `broadcastWith()` |
| `socket_id` | номер соединения конкретной вкладки | заголовок `X-Socket-ID`, `toOthers()` |

### Три вида связи

Веб-сокет в этой схеме — только одна из трёх связей, и их легко перепутать:

```
Браузер A ──HTTP──► Laravel ──HTTP──► Reverb ══WebSocket══► Браузер B
```

| Кто → кому | Протокол | Пример |
| --- | --- | --- |
| Браузер → Laravel | HTTP | `POST /chats/5/messages` (axios), `POST /broadcasting/auth` (Echo) |
| Laravel → Reverb | HTTP | `POST http://localhost:8080/apps/{REVERB_APP_ID}/events` |
| Reverb → браузеры | WebSocket | фрейм с событием во все подписанные соединения |

Laravel веб-сокетов не держит: он отправляет событие Reverb одним HTTP-запросом и забывает о нём. Именно этот запрос падает с `cURL error 7`, если Reverb не запущен (раздел 20).

### Что видно во вкладке WS

Фреймы идут в формате протокола Pusher: JSON с полями `event`, `channel` и `data`. При открытии страницы чата порядок такой:

| Направление | Фрейм | Что значит |
| --- | --- | --- |
| Reverb → браузер | `pusher:connection_established` | соединение открыто, в `data` — `socket_id` этой вкладки |
| браузер → Reverb | `pusher:subscribe` | «подпиши на канал», с подписью от `/broadcasting/auth` (раздел 4) |
| Reverb → браузер | `pusher_internal:subscription_succeeded` | подписка принята |
| Reverb → браузер | `message.created`, `notification.created` | ваши события (разделы 10 и 15) |
| браузер ↔ Reverb | `pusher:ping` / `pusher:pong` | проверка связи примерно после 30 секунд тишины, чтобы соединение не закрылось |

Как по шагам проходит отправка сообщения, показывает схема в разделе 11.

### Что передаётся: данные целиком или сигнал

Что лежит в событии, решает метод `broadcastWith()`. Два события проекта устроены по-разному.

**Сообщение чата приходит целиком:**

```json
{
    "event": "message.created",
    "channel": "private-chats.5.messages",
    "data": "{\"message\":{\"id\":42,\"author_id\":3,\"content\":\"Привет!\",\"created_at\":\"2026-09-15T05:18:27.000000Z\",\"author\":{\"id\":3,\"nickname\":\"ivan\",\"img_path\":null}}}"
}
```

`data` — это JSON внутри JSON: так устроен протокол Pusher, Echo распакует его сам. Внутри тот же `MessageResource`, что в ответе на отправку. Собеседник сразу рисует сообщение и больше никуда не ходит.

`socket_id` отправителя в данных нет. Laravel передаёт его Reverb отдельным параметром, чтобы тот знал, какую вкладку пропустить (`toOthers()`, раздел 11).

**Уведомление приходит одним числом:**

```json
{
    "event": "notification.created",
    "channel": "private-profiles.7.notifications",
    "data": "{\"notifications_count\":3}"
}
```

Текста уведомления в событии нет. Колокольчику нужна только цифра, а список по-прежнему подгружается по клику обычным HTTP.

Два подхода в общем виде:

| | Данные целиком | Сигнал «что-то изменилось» |
| --- | --- | --- |
| Что в событии | всё, что нужно для показа | только факт или id |
| Лишний запрос | не нужен | браузер идёт за данными по HTTP |
| Когда подходит | данные одинаковы для всех, кто их получит | всегда: сервер соберёт данные под каждого зрителя |

Сообщение можно слать целиком потому, что в 32-м уроке его JSON сделали одинаковым для всех участников. Будь в нём `is_mine` или `can_message`, пришлось бы слать сигнал. Иначе собеседник получил бы флаги, посчитанные для отправителя.

## 4. Каналы: публичные и приватные

| Класс | Кто может слушать | Когда подходит |
| --- | --- | --- |
| `Channel` | любой, кто знает имя канала | общедоступное: «в ленте вышел новый пост» |
| `PrivateChannel` | тот, кому разрешило правило в `routes/channels.php` | всё личное: переписка, уведомления |
| `PresenceChannel` | как приватный, плюс все видят список подключённых | «кто сейчас в чате» |

На уроке событие отправляется в публичный канал `new Channel('chats.'.$id.'.store')`. Для чата это дыра. Номера чатов идут подряд, и любой вошедший пользователь может из консоли браузера подписаться на `chats.1.store`, `chats.2.store` и дальше. Страница чужого чата ответит ему 403, отправка сообщения — тоже 403 (32-й урок), а чтение чужой переписки оказалось бы открыто.

Поэтому в проекте оба канала приватные. Правило «чат доступен только участникам» появляется в третьем месте, и это снова вызов `Chat::hasParticipant()`:

| Где | Как проверяется |
| --- | --- |
| страница чата | `abort_unless($chat->hasParticipant(...), 403)` в `ChatController::show()` |
| отправка сообщения | `Message\StoreRequest::authorize()` |
| получение сообщений | правило канала в `routes/channels.php` — **новое** |

Как Echo подписывается на приватный канал:

```
Браузер участника                      Reverb                   Laravel
─────────────────                      ──────                   ───────
echo().private('chats.5.messages')

1. открывает соединение ────────────►  выдаёт socket_id

2. POST /broadcasting/auth ─────────────────────────────────►  routes/channels.php
   socket_id                                                    шаблон 'chats.{chat}.messages'
   channel_name=private-chats.5.messages                        Chat #5 — привязка модели
                                                                hasParticipant() → true
   ◄───────────────────────────────── 200 + подпись ─────────  (false → 403)

3. «подпиши меня» + подпись ────────►  проверяет подпись,
                                       подписывает
```

Маршрут `/broadcasting/auth` регистрирует сам Laravel (раздел 7), писать его не нужно. CSRF-токен на нём не проверяется: Laravel исключает этот маршрут из проверки.

---

# Часть II. Pusher

Эта часть — конспект урока: как подключить облачный Pusher. В проекте его не ставим, но всё, что написано в частях IV и V (событие, каналы, Vue), с Pusher работает точно так же.

## 5. Бродкаст через pusher.com

**1. Приложение в Pusher.** Зарегистрироваться на pusher.com и в разделе **Channels** создать приложение: имя, кластер (регион серверов; нам ближе всего `eu`), в подсказках выбрать Vue и Laravel. На вкладке **App Keys** будут четыре значения: `app_id`, `key`, `secret`, `cluster`.

**2. Установка.**

```
php artisan install:broadcasting --pusher
```

Команда спросит четыре ключа из панели, а потом:

- поставит PHP-пакет `pusher/pusher-php-server` — через него Laravel отправляет события;
- опубликует `config/broadcasting.php` и создаст `routes/channels.php`;
- пропишет `channels:` в `bootstrap/app.php`;
- запишет ключи в `.env` и поставит `BROADCAST_CONNECTION=pusher`;
- поставит JS-пакеты `laravel-echo` и `pusher-js` и подключит Echo на фронтенде.

`.env` после установки:

```ini
BROADCAST_CONNECTION=pusher

PUSHER_APP_ID="1234567"
PUSHER_APP_KEY="a1b2c3d4e5f6"
PUSHER_APP_SECRET="f6e5d4c3b2a1"
PUSHER_APP_CLUSTER="eu"
PUSHER_PORT=443
PUSHER_SCHEME="https"

VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
VITE_PUSHER_HOST="${PUSHER_HOST}"
VITE_PUSHER_PORT="${PUSHER_PORT}"
VITE_PUSHER_SCHEME="${PUSHER_SCHEME}"
```

Переменные с префиксом `VITE_` Vite встраивает в JavaScript, и их видит любой посетитель сайта. `key` для этого и предназначен: он публичный и только называет приложение. **`secret` с префиксом `VITE_` не дублируют никогда.** Этим ключом Laravel подписывает события и разрешения на приватные каналы, и в браузере он позволил бы подделать и то, и другое.

**3. Echo в браузере.** На уроке Echo создаётся в `resources/js/bootstrap.js`:

```js
import axios from 'axios';
window.axios = axios;
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Echo для Pusher (и для Reverb) работает поверх библиотеки pusher-js.
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true,
});
```

И дальше во Vue используется глобальный `Echo`:

```js
created() {
    Echo.channel(`chats.${this.chat.id}.store`)
        .listen('.messages.broadcast', (e) => {
            this.messages.push(e.message);
        });
},
```

В нашем проекте файла `bootstrap.js` нет, а в `package.json` есть `vue`. Поэтому установщик Laravel 13 идёт другим путём: ставит пакет `@laravel/echo-vue` и должен дописать в `app.js` короткую настройку. В проекте с `app.js` (а не `app.ts`) он этот шаг пропускает с предупреждением (раздел 7), и настройку пишем руками:

```js
import { configureEcho } from '@laravel/echo-vue';

configureEcho({
    broadcaster: 'pusher',
});
```

Ключи `configureEcho()` сам возьмёт из `VITE_PUSHER_*`. Это тот же Echo, только созданный внутри пакета. Как им пользоваться в компонентах — раздел 8.

**4. Отладка.** В панели Pusher есть вкладка **Debug Console**: там видно каждое подключение, подписку и событие вместе с данными. Если сообщение не пришло в браузер, первым делом смотрят туда. Событие в консоли есть — ошибка во Vue. События нет — ошибка в Laravel.

## 6. Pusher или Reverb

### Зачем нужен внешний сервис

Свой веб-сокет-сервер — это отдельный процесс, который нужно обслуживать. На настоящем сервере с Reverb придётся:

- держать `reverb:start` запущенным круглосуточно и перезапускать после падения (Supervisor, как у воркера очереди);
- настроить Nginx, чтобы снаружи работал защищённый `wss://`;
- открыть порт и следить за нагрузкой: каждая открытая вкладка — постоянное соединение;
- когда один сервер перестанет справляться, связать несколько через Redis.

На обычном виртуальном хостинге этого часто не сделать вообще: долгоживущие процессы и свои порты там запрещены.

**Pusher берёт всё это на себя.** Запускать ничего не нужно, `wss://` работает сразу, соединения держат его серверы. Laravel отправляет такой же HTTP-запрос с событием, только на адрес Pusher вместо `localhost:8080`. В панели есть Debug Console и статистика.

**Чем за это платят:**

- бесплатный тариф ограничен по числу сообщений и подключений, дальше платно;
- данные идут через чужие серверы, то есть текст переписки видит сторонний сервис (у Pusher есть каналы со сквозным шифрованием, но их включают отдельно);
- без интернета не работает даже локальная разработка;
- задержка зависит от расстояния до выбранного кластера.

**Почему на уроке Pusher.** Долгое время своего веб-сокет-сервера у Laravel не было, и Pusher был стандартным выбором. Reverb появился в 2024 году вместе с Laravel 11. Он говорит на том же протоколе, поэтому переход между ними — смена настроек, а не кода.

### Сравнение

| | Pusher | Reverb |
| --- | --- | --- |
| Где работает | облако pusher.com | ваш процесс `php artisan reverb:start` |
| Ключи | из панели Pusher | генерирует `reverb:install` |
| Интернет при разработке | нужен | не нужен |
| Стоимость | бесплатный тариф с лимитами на сообщения и подключения | бесплатно |
| Отладка | Debug Console в панели | `php artisan reverb:start --debug` |
| На проде | поднимать ничего не нужно | держать процесс запущенным, как воркер очереди |
| Библиотека в браузере | `pusher-js` | тот же `pusher-js`: Reverb понимает протокол Pusher |

**Как выбирать.** Pusher — когда своего сервера нет или обслуживать его не хочется, а проект небольшой. Reverb — когда есть свой VPS, не хочется платить за сообщения и отдавать данные наружу, а разработка должна работать без интернета.

В проекте работает Reverb. Не нужны аккаунт и интернет, ключи не уходят в чужой сервис, а сервер запускается вместе с `composer run dev`. Если понадобится Pusher, поменяются `.env` и слово `'reverb'` в `configureEcho()`. События, каналы и Vue-компоненты останутся прежними.

---

# Часть III. Reverb в проекте

## 7. `install:broadcasting --reverb`

```
php artisan install:broadcasting --reverb
```

Что изменится:

| Что | Изменение |
| --- | --- |
| `composer.json` | пакет `laravel/reverb` |
| `config/broadcasting.php` | опубликован: настройки драйверов |
| `config/reverb.php` | опубликован: настройки сервера Reverb |
| `routes/channels.php` | создан с примером канала |
| `bootstrap/app.php` | в `withRouting()` добавлена строка `channels:` |
| `.env` | `BROADCAST_CONNECTION=reverb` и ключи `REVERB_*` |
| `package.json` | `laravel-echo`, `pusher-js`, `@laravel/echo-vue` |
| `resources/js/app.js` | импорт и вызов `configureEcho()` |

Так выглядит установка, когда всё проходит гладко. Как она прошла в нашем проекте — в конце раздела.

`.env` (ключи у вас будут свои):

```ini
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=123456
REVERB_APP_KEY=kq8wz3...
REVERB_APP_SECRET=p0vx7...
REVERB_HOST="localhost"
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Устроено так же, как у Pusher. `REVERB_HOST` и `REVERB_PORT` говорят, где искать сервер: по ним Laravel отправляет события, а браузер через `VITE_REVERB_*` открывает соединение. Сайт открывается на `http://line.test`, а сокет — на `ws://localhost:8080`. Для веб-сокета это нормально: адреса сайта и сокет-сервера могут различаться.

После установки загляните в `.env.example`: если блока `REVERB_*` там нет, перенесите его без значений ключей, чтобы новый разработчик знал об этих переменных.

`bootstrap/app.php` — установщик добавит одну строку после `commands:`:

```php
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
```

`channels:` делает две вещи: подключает `routes/channels.php` с правилами каналов и регистрирует маршрут `/broadcasting/auth` в группе `web`. Группа `web` означает сессию, поэтому на этом маршруте Laravel знает, кто вошёл, так же как на обычных страницах.

### Как установка прошла в проекте

На Windows установщик справился не со всем, и часть шагов пришлось выполнить руками. Рабочий порядок получился такой.

**1. `php artisan install:broadcasting --reverb`.** Команда опубликовала `config/broadcasting.php`, создала `routes/channels.php`, дописала `channels:` в `bootstrap/app.php` и поставила npm-пакеты. Три вещи у неё не вышли:

- **Reverb не встал.** Composer сообщил о конфликте: Reverb 1.x требует `guzzlehttp/psr7 ^2.6`, а в проекте стоит Guzzle 8, которому нужен psr7 3.x. Composer откатил `composer.json`, но установщик всё равно написал «Reverb installed successfully». Этой строке верить нельзя: смотреть нужно на вывод Composer выше неё.
- **`configureEcho()` не дописан в `app.js`.** Предупреждение `Could not find file [...app.ts]. Skipping automatic Echo configuration`: установщик должен проверить и `app.ts`, и `app.js`, но из-за ошибки в его коде находится только `app.ts`. Настройку пишем руками (раздел 8).
- **В `.env` появилась вторая строка `BROADCAST_CONNECTION=reverb`.** Старую строку установщик ищет с переводом строки Windows (`\r\n`), а `.env` сохранён с `\n`. Не нашёл и дописал новую в конец.

**2. Вернуть `.env` в рабочее состояние.** Удалить дописанную строку и оставить `BROADCAST_CONNECTION=log`. Пока ключей `REVERB_*` нет, драйвер `reverb` включать нельзя: `routes/channels.php` создаёт драйвер при каждом запуске artisan, и любая команда падает с `Failed to create broadcaster for connection "reverb"`. Упал бы и следующий шаг: в конце установки Composer сам вызывает `php artisan package:discover`.

**3. Поставить Reverb отдельно.**

```
composer require laravel/reverb -w
```

`-w` разрешает Composer менять зависимости устанавливаемого пакета. Здесь это понижение Guzzle 8.1 → 7.15, а вместе с ним `psr7` 3 → 2. Laravel 13 работает с обеими версиями, а сам проект Guzzle напрямую не использует. Вариант `-W` (заглавная) не подходит: он заодно обновил бы фреймворк, Carbon, Monolog и другие пакеты, не связанные с уроком.

Версию в команде не пишем. На Windows `composer` запускается через `composer.bat`, и `cmd` съедает символ `^` даже в кавычках: `"laravel/reverb:^1.0"` превращается в требование ровно версии 1.0.0. Без версии Composer сам возьмёт последнюю подходящую и запишет в `composer.json` ограничение `^1.11`.

**4. Ключи и драйвер.**

```
php artisan reverb:install
```

Команда публикует `config/reverb.php`, дописывает в `.env` блок `REVERB_*` и в конце спрашивает `Would you like to enable the Reverb broadcasting driver?`. В терминале на Windows вопрос может не отобразиться, и кажется, что команда зависла. Достаточно нажать Enter: ответ по умолчанию «да» переключит `BROADCAST_CONNECTION` на `reverb`.

**5. Pint.** Строку `channels:` установщик тоже вставил с переводом `\r\n`. `vendor/bin/pint --dirty` приведёт переводы строк в `bootstrap/app.php` к общему виду.

## 8. `app.js`: Echo и глобальный axios

Импорт и вызов `configureEcho()` установщик должен был дописать сам, но пропустил этот шаг (раздел 7). Поэтому весь блок пишем руками, вместе со строкой `window.axios = axios`. Верх `resources/js/app.js`:

```js
import '../css/app.css';

import axios from 'axios';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { configureEcho } from '@laravel/echo-vue';

// Echo добавляет заголовок X-Socket-ID только к ГЛОБАЛЬНОМУ axios — window.axios.
// По этому заголовку toOthers() на сервере узнаёт, какой вкладке событие
// не отправлять (раздел 11). Компоненты импортируют тот же самый экземпляр
// axios, поэтому заголовок получат и их запросы.
//
// Строка стоит до configureEcho(): заголовок подключается в момент создания
// Echo, и глобальный axios к этому времени уже должен существовать.
window.axios = axios;

// Ключи и адрес сервера configureEcho() берёт из VITE_REVERB_* сам.
// Соединение откроется при первом вызове echo() в компоненте.
configureEcho({
    broadcaster: 'reverb',
});
```

Остальная часть файла (`createInertiaApp`) не меняется.

**Зачем `window.axios`.** Раньше скелет Laravel клал axios в `window` в файле `bootstrap.js` — это видно и на уроке. В нашем проекте `bootstrap.js` нет, axios импортируется прямо в компонентах. Без глобального axios Echo не добавит заголовок, `toOthers()` молча перестанет работать, и отправитель увидит своё сообщение дважды (раздел 11).

**Как получить Echo в компоненте.** Пакет `@laravel/echo-vue` даёт два способа:

| | Как | Отписка |
| --- | --- | --- |
| `useEcho(...)` | хук для `<script setup>` (Composition API) | сама, когда компонент уничтожается |
| `echo()` | функция, возвращает экземпляр Echo | руками, в `beforeUnmount()` |

Компоненты проекта написаны в Options API (`export default { data(), methods }`), поэтому берём `echo()`. `echo().private(...)` — то же самое, что `Echo.private(...)` на уроке.

## 9. Запуск: `reverb:start` в `composer run dev`

Reverb — отдельный процесс, как воркер очереди из 29-го урока. Пока он не запущен, событиям некуда уходить. Чтобы вся среда по-прежнему поднималась одной командой, добавляем его в скрипты `composer.json`:

```json
        "dev": [
            "Composer\\Config::disableProcessTimeout",
            "npx concurrently -c \"#c4b5fd,#fdba74,#86efac\" \"php artisan queue:listen --tries=1 --timeout=0\" \"npm run dev\" \"php artisan reverb:start\" --names=queue,vite,reverb --kill-others"
        ],
        "dev:serve": [
            "Composer\\Config::disableProcessTimeout",
            "npx concurrently -c \"#93c5fd,#c4b5fd,#fdba74,#86efac\" \"php artisan serve\" \"php artisan queue:listen --tries=1 --timeout=0\" \"npm run dev\" \"php artisan reverb:start\" --names=server,queue,vite,reverb --kill-others"
        ],
```

После правки `composer run dev` нужно **перезапустить**. Причин две: появился новый процесс `reverb`, а новые переменные `VITE_REVERB_*` Vite читает из `.env` только при старте.

Чтобы видеть каждое подключение и каждое событие, Reverb можно запустить отдельно с флагом `--debug`:

```
php artisan reverb:start --debug
```

Это аналог Debug Console у Pusher. Держать флаг в `composer run dev` постоянно не стоит: вывод забивается служебными сообщениями.

---

# Часть IV. Сообщения чата

## 10. Событие `SendMessageEvent`

```
php artisan make:event WS/SendMessageEvent
```

Заготовка `make:event` ещё ничего не транслирует: интерфейса в ней нет, а в `broadcastOn()` стоит канал-пример `channel-name`. Дописываем интерфейсы, свойство с сообщением, `broadcastAs()` и `broadcastWith()`.

`app/Events/WS/SendMessageEvent.php`:

```php
<?php

namespace App\Events\WS;

use App\Http\Resources\Message\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Новое сообщение чата — остальным участникам, у которых чат открыт.
 *
 * ShouldBroadcastNow: событие уходит в Reverb прямо во время запроса,
 * без очереди.
 *
 * ShouldRescue: если Reverb недоступен, ошибка уйдёт в лог, а запрос
 * завершится как обычно. Сообщение к этому моменту уже сохранено,
 * и отправитель должен получить 201, а не 500.
 *
 * Трейт InteractsWithSockets нужен для toOthers(): в нём хранится
 * socket_id вкладки, которой событие не отправлять.
 */
class SendMessageEvent implements ShouldBroadcastNow, ShouldRescue {
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Сообщение должно прийти с загруженным автором: без него
     * в broadcastWith() не будет ника (whenLoaded в MessageResource).
     */
    public function __construct(
        public Message $message,
    ) {}

    /**
     * Приватный канал чата. Префикс private- в имени не пишем:
     * его добавит сам класс PrivateChannel.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array {
        return [
            new PrivateChannel('chats.'.$this->message->chat_id.'.messages'),
        ];
    }

    /**
     * Имя события. Во Vue его слушают с точкой в начале: .message.created.
     */
    public function broadcastAs(): string {
        return 'message.created';
    }

    /**
     * Данные для браузера — тот же MessageResource, что у маппера
     * и у ответа на отправку.
     *
     * @return array{message: array<string, mixed>}
     */
    public function broadcastWith(): array {
        return [
            'message' => MessageResource::make($this->message)->resolve(),
        ];
    }
}
```

Разберём по частям.

### `ShouldBroadcastNow`: сразу или через очередь

| | `ShouldBroadcast` | `ShouldBroadcastNow` |
| --- | --- | --- |
| Когда уходит | задачей в очереди, её выполнит воркер | прямо во время запроса |
| Нужен `queue:listen` | да | нет |
| Ответ пользователю | не ждёт отправки | ждёт отправки (локально — миллисекунды) |
| Модель в событии | воркер заново читает её из базы | тот же объект из памяти |

Документация Laravel по умолчанию советует очередь: трансляция не должна замедлять ответ. На уроке выбран `ShouldBroadcastNow`, и мы его оставляем. Чату нужна доставка без задержки, урок не зависит от того, запущен ли воркер, а для уведомлений очередь приводит к конкретной ошибке (раздел 15).

### `ShouldRescue`: трансляция — не главное

С `ShouldBroadcastNow` отправка идёт внутри запроса. Если Reverb не запущен, Laravel бросит исключение, и `storeMessage()` вернёт 500. Но сообщение к этому моменту **уже сохранено**. Пользователь увидит «Не удалось отправить сообщение», нажмёт кнопку ещё раз, и в чате появится дубль.

`ShouldRescue` ловит ошибку трансляции, пишет её в лог и даёт запросу завершиться. Сообщение сохранено, отправитель получил 201. Собеседник увидит сообщение, когда откроет чат заново, — как было до этого урока. Обратная сторона: если события не доходят, в браузере ошибки не будет, и искать её нужно в `storage/logs/laravel.log`.

### `broadcastOn()`: куда

Канал `chats.5.messages` — «сообщения чата 5», по той же логике, что адрес отправки `chats/5/messages`. На уроке канал называется `chats.5.store`. Мы называем канал по тому, **что** в нём передаётся, а не по действию контроллера.

### `broadcastAs()`: как называется

Без `broadcastAs()` событие называлось бы по имени класса — `App\Events\WS\SendMessageEvent`. Echo подставляет перед именем `App.Events.`, поэтому слушать пришлось бы `'WS.SendMessageEvent'`. Короткое имя не зависит от того, где лежит класс. **Точка в начале** (`.message.created`) говорит Echo: «имя как есть, ничего перед ним не подставляй». Забыть точку — самая частая ошибка урока.

Имя `message.created` значит «сообщение создано», по аналогии с событием Eloquent `created`. На уроке событие называется `messages.broadcast`, но это имя говорит, *как* доставлено сообщение, а не *что* случилось.

### `broadcastWith()`: что

Без `broadcastWith()` Laravel отправил бы все публичные свойства события, а модель `$message` превратил бы в массив через её `toArray()`. Туда попадает всё, что сейчас лежит в модели: колонки без скрытых атрибутов из `$hidden`, значения после преобразований из `casts()` и уже загруженные связи. Контроллер делает `load('author')` до отправки, поэтому автор тоже уехал бы в данные — целым профилем со всеми нескрытыми колонками.

Такой JSON работает, но его состав никто не выбирал. Появится новая колонка в таблице или загрузится ещё одна связь — и собеседник молча получит больше, чем раньше. `broadcastWith()` нужен ради явного и стабильного состава данных, и описывает этот состав **тот же `MessageResource`**.

Здесь окупается главное решение 32-го урока. В `MessageResource` нет ничего, что зависит от смотрящего: ни `is_mine`, ни `can_message` у автора. Поэтому JSON, собранный в запросе отправителя, можно без переделки отдать собеседнику. `ItemMessage` у собеседника сравнит `author_id` со своим профилем и нарисует сообщение слева на сером фоне.

## 11. Отправка: `broadcast()->toOthers()`

`app/Http/Controllers/Client/ChatController.php`, метод `storeMessage()`:

```php
    /**
     * Отправка сообщения в чат.
     *
     * Проверки доступа здесь нет: участие проверил StoreRequest::authorize(),
     * посторонний до этого метода не дойдёт.
     *
     * Отправитель получает сообщение ответом на запрос, остальные
     * участники — событием через веб-сокет.
     */
    public function storeMessage(StoreRequest $request, Chat $chat): JsonResponse {
        // create() на связи hasMany сам заполнит chat_id,
        // author_id и content пришли из validated().
        $message = $chat->messages()->create($request->validated());

        // Автор нужен и ответу, и событию: оба отдают сообщение через
        // MessageResource. Поэтому load() стоит раньше broadcast().
        $message->load('author');

        // toOthers(): всем подписчикам канала, кроме вкладки, из которой пришёл
        // запрос. Она получит сообщение ответом ниже, и копия из канала
        // встала бы в её ленту второй раз.
        broadcast(new SendMessageEvent($message))->toOthers();

        // 201 Created и само сообщение в теле — как у комментария.
        return response()->json(MessageResource::make($message)->resolve(), 201);
    }
```

Импорт: `use App\Events\WS\SendMessageEvent;`.

**`toOthers()` исключает вкладку, а не пользователя.** С каждым запросом axios Echo отправляет заголовок `X-Socket-ID` — номер соединения этой вкладки. `toOthers()` запоминает его в событии (для этого нужен трейт `InteractsWithSockets`), и Reverb не отправит событие этому соединению. Если у отправителя тот же чат открыт во второй вкладке, туда сообщение придёт через канал. Так и должно быть: вторая вкладка ответа на запрос не получала.

```
Вкладка A (отправитель)        Laravel                          Reverb            Вкладка B (собеседник)
POST /chats/5/messages ────►  create(), load('author')
X-Socket-ID: 111.1            broadcast(...)->toOthers() ─────►  канал
                                                                 private-chats.5.messages
                                                                 всем, кроме 111.1 ──────►  .message.created
◄──── 201 + JSON сообщения                                                                 push в ленту
push в ленту
```

## 12. Правило канала: `routes/channels.php`

Установщик создаёт файл с примером:

```php
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

Это канал для встроенных уведомлений Laravel (`$user->notify()` с каналом `broadcast`). Встроенные уведомления мы в 30-м уроке сознательно не взяли, поэтому пример удаляем и пишем свой канал.

`routes/channels.php`:

```php
<?php

use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Правила доступа к приватным каналам. Перед подпиской Echo спрашивает их
// запросом POST /broadcasting/auth: true — подписка разрешена, false — 403,
// и события канала этот браузер не получит.
//
// Первый аргумент функции — вошедший пользователь: гостя Laravel отклонит сам,
// до вызова функции. Остальные аргументы — части имени канала в фигурных
// скобках, с той же неявной привязкой моделей, что у маршрутов: {chat} → Chat $chat.
//
// Имя канала пишется без префикса private-: его добавляют PrivateChannel
// в событии и echo().private() во Vue.

// Сообщения чата слушают только участники — то же правило, что у страницы
// чата и у отправки сообщения.
Broadcast::channel('chats.{chat}.messages', function (User $user, Chat $chat): bool {
    return $chat->hasParticipant($user->profile);
});
```

Файл устроен как `routes/client.php`: шаблон с параметром, привязка модели, функция. Разница одна: функция возвращает не ответ, а «можно или нельзя». Для несуществующего чата привязка модели не найдёт запись, и Laravel ответит 403.

Список зарегистрированных каналов показывает команда:

```
php artisan channel:list
```

## 13. Подписка в `Show.vue`

`resources/js/Pages/Client/Chat/Show.vue` — шаблон не меняется. В скрипт добавляются импорт, `computed` и два хука:

```js
<script>
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import ItemMessage from '@/Components/Message/ItemMessage.vue';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link, ItemMessage },
    props: {
        // chat и messages — без изменений
    },
    data() {
        // без изменений
    },
    computed: {
        /**
         * Канал этого чата. Имя совпадает с broadcastOn() в SendMessageEvent,
         * только без префикса private-: его добавит echo().private().
         */
        messagesChannel() {
            return `chats.${this.chat.id}.messages`;
        },
    },
    /**
     * Подписка на новые сообщения, пока страница чата открыта.
     *
     * private(), а не channel(): канал приватный, и Echo сначала спросит
     * разрешение у /broadcasting/auth.
     */
    created() {
        echo()
            .private(this.messagesChannel)
            // Точка в начале: имя задано в broadcastAs(), и подставлять
            // перед ним App.Events не нужно.
            .listen('.message.created', (e) => {
                // e — то, что вернул broadcastWith(): { message: {...} }.
                // Та же форма, что у res.data в storeMessage(), поэтому
                // сообщение встаёт в ту же ленту и рисуется тем же ItemMessage.
                this.chatMessages.push(e.message);
            });
    },
    /**
     * Отписка при уходе со страницы.
     *
     * Echo живёт, пока открыта вкладка, и об уходе со страницы сам не узнает.
     * Без leave() браузер продолжит получать сообщения чата на любой другой
     * странице, а каждое возвращение в чат добавит ещё один обработчик.
     *
     * beforeUnmount — последний момент, когда компонент целиком на месте:
     * отписываемся раньше, чем начнёт работу следующая страница.
     */
    beforeUnmount() {
        echo().leave(this.messagesChannel);
    },
    methods: {
        // storeMessage() — без изменений
    },
};
</script>
```

Отличия от кода урока:

- **`echo()` вместо глобального `Echo`.** Глобальную переменную создавал `bootstrap.js` урока. В проекте Echo живёт внутри `@laravel/echo-vue` и достаётся функцией `echo()` (раздел 8).
- **`private()` вместо `channel()`** — канал приватный (раздел 4).
- **`chatMessages` вместо `messages`.** На уроке `this.messages.push(...)` пишет прямо в проп. Это та же ошибка, что разбиралась в 32-м уроке: проп принадлежит серверу, новые сообщения дописываются в локальную копию.
- **Отписка в `beforeUnmount()`.** На уроке её нет.

На уроке обработчик сначала делает `console.log(e)`. Для первого запуска это хороший приём: сразу видно, в какой форме пришли данные.

---

# Часть V. Уведомления

## 14. Где отправлять: `NotificationObserver::created()`

Уведомления создают три обсервера: `CommentObserver`, `PostObserver` (репост) и `LikeObserver`. Отправлять событие можно из каждого, но у всех трёх оно одинаковое: «у профиля появилось новое уведомление». Поэтому место одно — момент создания самого уведомления. У модели `Notification` обсервер уже есть (он помечает уведомления прочитанными), в него добавляется метод `created()`.

Так живой колокольчик получают и репосты, без отдельного кода. Задание говорит о лайках и комментариях. Отправлять событие для всех уведомлений — решение, принятое при подготовке урока: повод у уведомлений разный, а реакция на новое уведомление одна.

`app/Observers/NotificationObserver.php` — новый метод:

```php
use App\Events\WS\SendNotificationEvent;

    /**
     * Уведомление создано — сообщаем получателю через веб-сокет.
     *
     * Здесь, а не в LikeObserver, CommentObserver и PostObserver: поводы
     * у уведомлений разные, а «появилось новое — обнови колокольчик» одно
     * для всех. Появится новый повод — событие уйдёт и для него, без правок.
     *
     * Без toOthers(): получатель — не тот, кто выполнил действие, исключать
     * некого. А пока в обсерверах временно отключена проверка «себе о себе»,
     * toOthers() даже помешал бы: лайк своего поста не обновил бы колокольчик
     * во вкладке, из которой лайк поставили.
     */
    public function created(Notification $notification): void {
        broadcast(new SendNotificationEvent($notification));
    }
```

Метод `retrieved()` не меняется. Предупреждение «УЧЕБНЫЙ ЭКСПЕРИМЕНТ» переносим из docblock класса в docblock `retrieved()`: теперь в обсервере два метода, и эксперимент — только один из них. У класса остаётся короткое описание:

```php
/**
 * Реакции на события уведомления: рассылка нового уведомления получателю
 * и пометка прочитанного при выборке.
 */
class NotificationObserver {
```

## 15. Событие `SendNotificationEvent`

```
php artisan make:event WS/SendNotificationEvent
```

`app/Events/WS/SendNotificationEvent.php`:

```php
<?php

namespace App\Events\WS;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Новое уведомление — получателю, чтобы колокольчик обновился без перезагрузки.
 *
 * ShouldBroadcastNow здесь обязателен, а не просто повторяет урок:
 * через очередь уведомление успело бы стать «прочитанным» до отправки (раздел 15).
 *
 * ShouldRescue — по той же причине, что у SendMessageEvent: лайк
 * и комментарий не должны падать с 500, если Reverb недоступен.
 */
class SendNotificationEvent implements ShouldBroadcastNow, ShouldRescue {
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Notification $notification,
    ) {}

    /**
     * Канал ПОЛУЧАТЕЛЯ: profile_id — кому адресовано уведомление,
     * actor_id — кто его вызвал. Перепутать их — и колокольчик вырастет
     * у того, кто поставил лайк.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array {
        return [
            new PrivateChannel('profiles.'.$this->notification->profile_id.'.notifications'),
        ];
    }

    public function broadcastAs(): string {
        return 'notification.created';
    }

    /**
     * Колокольчику нужно одно число — сколько теперь непрочитанных.
     *
     * Считает сервер, а не браузер прибавляет единицу: пока страница была
     * открыта, получатель мог прочитать часть уведомлений в другой вкладке.
     * Тот же довод, что у reposts_count в PostController::storeRepost().
     *
     * @return array{notifications_count: int}
     */
    public function broadcastWith(): array {
        return [
            'notifications_count' => $this->notification->profile->notifications_count,
        ];
    }
}
```

**Почему в данных только число, а не `NotificationResource`.** Колокольчику нужно число, список подгружается по клику, как в 30-м уроке. К тому же `NotificationResource` рассчитан на попап: его `is_read` берётся из флага `wasUnread`, который ставит обсервер при выборке. У только что созданного уведомления этого флага нет, и ресурс показал бы новое уведомление прочитанным.

### Почему здесь нельзя `ShouldBroadcast`

Кажется, что достаточно заменить `ShouldBroadcastNow` на `ShouldBroadcast`, и уведомления уйдут через очередь. Но в этом проекте так сломается сам колокольчик, и без единой ошибки:

```
1. Лайк создал уведомление → событие ушло в очередь.
   В задачу записан не объект, а только id уведомления (так работает SerializesModels).
2. Воркер берёт задачу и заново читает уведомление из базы.
3. Чтение из базы поднимает retrieved → NotificationObserver из 30-го урока
   ставит read_at: уведомление «прочитано».
4. broadcastWith() считает непрочитанные — нового среди них уже нет.
5. Колокольчик у получателя не растёт, а в попапе уведомление серое,
   хотя его никто не видел.
```

С `ShouldBroadcastNow` событие работает с тем же объектом, который только что создан, и из базы его никто не перечитывает. Перевести уведомления на очередь можно будет, когда эксперимент с `retrieved` заменят отдельным маршрутом «пометить прочитанными» (30-й урок, раздел 18, вариант А).

## 16. Канал уведомлений

В `routes/channels.php` добавляется второй канал. Файл целиком:

```php
<?php

use App\Models\Chat;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Правила доступа к приватным каналам. Перед подпиской Echo спрашивает их
// запросом POST /broadcasting/auth: true — подписка разрешена, false — 403,
// и события канала этот браузер не получит.
//
// Первый аргумент функции — вошедший пользователь: гостя Laravel отклонит сам,
// до вызова функции. Остальные аргументы — части имени канала в фигурных
// скобках, с той же неявной привязкой моделей, что у маршрутов: {chat} → Chat $chat.
//
// Имя канала пишется без префикса private-: его добавляют PrivateChannel
// в событии и echo().private() во Vue.

// Сообщения чата слушают только участники — то же правило, что у страницы
// чата и у отправки сообщения.
Broadcast::channel('chats.{chat}.messages', function (User $user, Chat $chat): bool {
    return $chat->hasParticipant($user->profile);
});

// Уведомления профиля слушает только его владелец. У пользователя без профиля
// слева окажется null, и сравнение вернёт false.
Broadcast::channel('profiles.{profile}.notifications', function (User $user, Profile $profile): bool {
    return $user->profile?->id === $profile->id;
});
```

Канал привязан к профилю, а не к пользователю, по той же причине, что и уведомления: получатель в `app_notifications` — `profile_id`.

## 17. Колокольчик в `ClientLayout.vue`

Подписка живёт в раскладке, а не на странице. Колокольчик есть на каждой странице клиентской части, а раскладка переживает переходы между страницами (30-й урок, раздел 12). Значит, одна подписка работает всё время, пока пользователь находится в клиентской части.

`resources/js/Layouts/ClientLayout.vue` — шаблон не меняется, скрипт:

```js
<script>
import axios from 'axios';
// Именованный импорт: из библиотеки берём только то, что нужно.
import { Link, router } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';

export default {
    name: 'ClientLayout',
    components: { Link },
    data() {
        return {
            isPopupShown: false,
            isLoading: false,
            notifications: [],
            // Имя канала уведомлений запоминаем при создании раскладки.
            // К моменту beforeUnmount() общие пропсы уже принадлежат новой
            // странице: например, после выхода auth.user станет null,
            // и вычислить имя канала заново не получится.
            notificationsChannel: null,
        };
    },
    computed: {
        // unreadCount() — без изменений
    },
    /**
     * Подписка на новые уведомления текущего профиля.
     */
    created() {
        const profileId = this.$page.props.auth.user.profile?.id;

        // Без профиля уведомлений не бывает: слушать нечего.
        if (!profileId) {
            return;
        }

        this.notificationsChannel = `profiles.${profileId}.notifications`;

        echo()
            .private(this.notificationsChannel)
            .listen('.notification.created', (e) => {
                // Число уже посчитано на сервере. replaceProp() подставляет его
                // в общий проп без запроса, и unreadCount пересчитается сам.
                router.replaceProp('auth.user.profile.notifications_count', e.notifications_count);
            });
    },
    /**
     * Отписка, когда раскладка исчезает: пользователь вышел или перешёл
     * на страницу с другой раскладкой.
     */
    beforeUnmount() {
        if (this.notificationsChannel) {
            echo().leave(this.notificationsChannel);
        }
    },
    methods: {
        // toggleNotifications() и loadNotifications() — без изменений
    },
};
</script>
```

### Как обновить число: три способа

Число на колокольчике — это общий проп `auth.user.profile.notifications_count` из `HandleInertiaRequests::share()`. Способов обновить его три:

| Способ | Что происходит | Подходит ли |
| --- | --- | --- |
| локальная переменная `+1` | в раскладке своё число, проп не трогаем | нет: локальное число и проп разойдутся после первого же открытия попапа (30-й урок, раздел 15) |
| `router.reload({ only: ['auth'] })` | запрос на текущий адрес за свежим `auth` | нет: лишний запрос на каждое уведомление, и контроллер текущей страницы (лента, чат) снова выполнит все свои запросы к базе |
| `router.replaceProp(...)` | подставляет готовое значение в проп без запроса | **да**: число уже пришло в событии |

`router.replaceProp()` — метод Inertia 2 для обновления пропа на клиенте. Путь к пропу пишется через точку. Состояние страницы и прокрутка при этом сохраняются, поэтому набранный текст сообщения и лента чата останутся на месте.

В 30-м уроке после открытия попапа правильным был `reload`. Тогда браузер не знал, сколько уведомлений осталось непрочитанными, и число приходилось спрашивать у сервера. Здесь сервер уже прислал его в событии, и спрашивать второй раз незачем.

---

# Часть VI. Тесты и проверка

## 18. Тесты

**Что проверяем.** Для каждого события: оно отправлено в правильный канал и с правильными данными. Для каждого канала: правило пускает своего и не пускает чужого.

**Как проверить событие.** `Event::fake([SendMessageEvent::class])` подменяет только это событие: обсерверы и модели работают по-настоящему, а отправленное событие запоминается. `Event::assertDispatched()` проверяет, что оно отправлено, и даёт посмотреть, что внутри.

**Почему правило канала не проверяется запросом на `/broadcasting/auth`.** В `phpunit.xml` стоит `BROADCAST_CONNECTION=null`, чтобы тесты ничего не отправляли в Reverb. У драйвера `null` проверка доступа пустая: он пускает в любой канал кого угодно. Тест «посторонний получает 403» был бы зелёным при любом правиле. Поэтому правило берём из списка зарегистрированных каналов по шаблону имени и вызываем как обычную функцию.

`tests/Feature/ClientMessageTest.php` — импорты и два новых теста:

```php
use App\Events\WS\SendMessageEvent;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
```

```php
    public function test_sent_message_is_broadcast_to_other_participants(): void {
        $viewer = Profile::factory()->create();
        $companion = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, $companion->id]);

        // Подменяем только это событие: всё остальное работает по-настоящему.
        Event::fake([SendMessageEvent::class]);

        $this->actingAs($viewer->user)
            // В браузере этот заголовок добавляет Echo. По нему toOthers()
            // запоминает, какой вкладке событие не отправлять.
            ->withHeader('X-Socket-ID', '1234.5678')
            ->postJson(route('client.chats.messages.store', $chat), [
                'content' => 'Привет!',
            ])
            ->assertCreated();

        Event::assertDispatched(SendMessageEvent::class, function (SendMessageEvent $event) use ($chat, $viewer): bool {
            // Канал именно этого чата: ошибка в имени — и собеседник ничего не получит.
            $this->assertSame('private-chats.'.$chat->id.'.messages', $event->broadcastOn()[0]->name);

            // Вкладка отправителя исключена. Без toOthers() здесь был бы null,
            // и сообщение встало бы в её ленту дважды.
            $this->assertSame('1234.5678', $event->socket);

            // В данных — сообщение с автором: load('author') стоит раньше broadcast().
            $message = $event->broadcastWith()['message'];
            $this->assertSame('Привет!', $message['content']);
            $this->assertSame($viewer->nickname, $message['author']['nickname']);

            return true;
        });
    }

    /**
     * Правило вызываем напрямую, а не запросом на /broadcasting/auth:
     * в тестах драйвер null, и он пускает в любой канал кого угодно.
     */
    public function test_only_participants_can_listen_to_chat_channel(): void {
        $viewer = Profile::factory()->create();

        $chat = Chat::factory()->create();
        $chat->profiles()->attach([$viewer->id, Profile::factory()->create()->id]);

        // Правила из routes/channels.php хранятся по шаблону имени канала.
        $canListen = Broadcast::driver()->getChannels()->get('chats.{chat}.messages');

        $this->assertTrue($canListen($viewer->user, $chat));
        $this->assertFalse($canListen(Profile::factory()->create()->user, $chat));
    }
```

`tests/Feature/ClientNotificationTest.php` — импорты и два новых теста:

```php
use App\Events\WS\SendNotificationEvent;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
```

```php
    public function test_new_notification_is_broadcast_to_recipient(): void {
        $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED]);
        $profile = Profile::factory()->create();

        Event::fake([SendNotificationEvent::class]);

        $this->actingAs($profile->user)
            ->postJson(route('client.posts.likes.toggle', $post))
            ->assertOk();

        Event::assertDispatched(SendNotificationEvent::class, function (SendNotificationEvent $event) use ($post): bool {
            // Канал АВТОРА поста, а не того, кто лайкнул: перепутать profile_id
            // и actor_id — самая вероятная ошибка в этом событии.
            $this->assertSame('private-profiles.'.$post->author_id.'.notifications', $event->broadcastOn()[0]->name);

            // Число уже учитывает новое уведомление: событие отправлено после
            // вставки строки.
            $this->assertSame(1, $event->broadcastWith()['notifications_count']);

            return true;
        });
    }

    /**
     * Правило вызываем напрямую — почему, см. ClientMessageTest.
     */
    public function test_only_owner_can_listen_to_notifications_channel(): void {
        $owner = Profile::factory()->create();

        $canListen = Broadcast::driver()->getChannels()->get('profiles.{profile}.notifications');

        $this->assertTrue($canListen($owner->user, $owner));
        $this->assertFalse($canListen(Profile::factory()->create()->user, $owner));
    }
```

**Чего здесь нет и почему.**

- **Тестов Vue-части**: подписка, отписка и `replaceProp` работают в браузере, а тесты проекта проверяют сервер. Их проверяем руками (раздел 19).
- **Теста на `ShouldRescue`**: перехват ошибки — поведение фреймворка, а не проекта.
- **Правок в существующих тестах.** Обсерверы теперь отправляют событие при каждом уведомлении, но в тестах драйвер `null`, и отправка проходит молча. Весь набор остаётся зелёным без изменений.

## 19. Проверка

1. Установить broadcasting по разделу 7: `install:broadcasting --reverb`, правка `.env`, `composer require laravel/reverb -w`, `reverb:install`. Проверить: в `.env` одна строка `BROADCAST_CONNECTION=reverb` и есть ключи `REVERB_*`; появились `routes/channels.php` и `config/reverb.php`; в `bootstrap/app.php` есть строка `channels:`; в `composer.json` есть `laravel/reverb`; в `package.json` есть `laravel-echo`, `pusher-js`, `@laravel/echo-vue`.
2. Запустить обе команды `make:event`, заполнить файлы по разделам 8–17 (включая `configureEcho()` в `app.js`), поправить скрипты в `composer.json`.
3. Перезапустить `composer run dev`. В выводе появился процесс `reverb` с сообщением о запуске сервера на порту 8080.
4. `php artisan channel:list` — два канала: `chats.{chat}.messages` и `profiles.{profile}.notifications`.
5. Открыть один чат пользователем A в обычном окне и пользователем B в другом браузере или в окне инкогнито: сессии должны быть разными. В DevTools → Network у каждого: запрос `POST /broadcasting/auth` с ответом `200`, на вкладке **WS** — соединение с `localhost:8080`.
6. A отправляет сообщение. У B оно появилось без перезагрузки, слева на сером фоне, с ником A. У A сообщение ровно одно, справа. У B в DevTools → WS → Messages видно событие `message.created` с данными сообщения.
7. У A в запросе `POST /chats/N/messages` есть заголовок `X-Socket-ID`. Если его нет, не задан `window.axios` (раздел 8).
8. A открывает тот же чат во второй вкладке и пишет из первой. Во второй вкладке сообщение появилось: `toOthers()` исключает вкладку, а не пользователя.
9. A уходит из чата в ленту и возвращается, B пишет. У A сообщение появилось один раз.
10. B открывает ленту. A лайкает пост B — число на колокольчике у B выросло без перезагрузки. A комментирует пост B — выросло ещё. A репостит пост B — выросло ещё.
11. B открывает попап — счётчик уменьшился, как в 30-м уроке. Следующий лайк от A увеличивает число уже от нового значения.
12. Пока в обсерверах отключена проверка «себе о себе», колокольчик можно проверить и одним аккаунтом: лайкнуть свой пост — число выросло прямо в этой вкладке.
13. Остановить `composer run dev`, запустить только `npm run dev` и отправить сообщение. Ответ `201`, сообщение сохранено, в `storage/logs/laravel.log` — ошибка отправки. Так работает `ShouldRescue`. Вернуть `composer run dev`.
14. `php artisan test --filter=ClientMessageTest` — семь тестов зелёные.
15. `php artisan test --filter=ClientNotificationTest` — десять тестов зелёные.
16. `php artisan test` — весь набор зелёный.
17. `vendor/bin/pint --dirty` — правок стиля нет (заготовки `make:event` пишут скобки не в стиле проекта, Pint их поправит).

## 20. Грабли

- **`Echo is not defined`** — во Vue написан глобальный `Echo`, как на уроке. В проекте Echo импортируется: `import { echo } from '@laravel/echo-vue'` и вызывается как `echo()`.
- **`install:broadcasting` пишет «Reverb installed successfully», а Reverb не установлен** — выше в выводе ошибка Composer `Your requirements could not be resolved` про `guzzlehttp/psr7`. Reverb 1.x несовместим с Guzzle 8: ставить через `composer require laravel/reverb -w` (раздел 7).
- **`requires laravel/reverb 1.0 (exact version match)`** — на Windows `cmd` съел `^` в `"laravel/reverb:^1.0"`. Писать команду без версии.
- **`WARN Could not find file [...app.ts]. Skipping automatic Echo configuration`** — ошибка установщика: `app.js` он не находит. `configureEcho()` дописывается руками (раздел 8).
- **Любая команда artisan падает с `Failed to create broadcaster for connection "reverb"` и `$auth_key must be of type string, null given`** — в `.env` уже стоит `BROADCAST_CONNECTION=reverb`, а ключей `REVERB_*` ещё нет. Вернуть `log`, выполнить `php artisan reverb:install`.
- **В `.env` две строки `BROADCAST_CONNECTION`** — установщик искал старую строку с переводом `\r\n`, а файл сохранён с `\n`, и дописал вторую. Оставить одну.
- **`php artisan reverb:install` висит** — ждёт ответа на вопрос, который терминал не показал. Нажать Enter.
- **Ошибка `Echo has not been configured`** — в `app.js` нет `configureEcho()`. В этом проекте установщик его не дописывает (раздел 7).
- **Node-зависимости не встали** — установщик покажет предупреждение с командой. Запустить её вручную: `npm install --save-dev laravel-echo pusher-js @laravel/echo-vue`.
- **`You must pass your app key when you instantiate Pusher`** — в браузер не попал `VITE_REVERB_APP_KEY`. Vite запущен раньше, чем в `.env` появились ключи: перезапустить `composer run dev`.
- **В консоли `WebSocket connection to 'ws://localhost:8080/...' failed`** — Reverb не запущен. Проверить, что в скрипте `dev` есть `reverb:start` и что `composer run dev` перезапущен.
- **Reverb не стартует, а вместе с ним останавливается весь `composer run dev`** — порт 8080 занят другой программой, а `--kill-others` гасит остальные процессы. Поменять в `.env` и `REVERB_SERVER_PORT` (порт, который слушает сервер), и `REVERB_PORT` (порт, к которому подключаются Laravel и браузер).
- **Сообщения не приходят, в браузере ошибок нет** — смотреть `storage/logs/laravel.log`. `ShouldRescue` пишет туда ошибку отправки, а не показывает её пользователю. Запись `Pusher error: cURL error 7: Failed to connect to localhost:8080` означает, что Reverb не запущен. Ещё один признак — отправка сообщения тормозит на пару секунд: столько Laravel ждёт ответа от несуществующего сервера.
- **Скрипт `dev` поправлен, а Reverb так и не запустился** — `composer run dev` читает `composer.json` только при старте. Процесс, запущенный до правки, работает по старому скрипту: его нужно остановить (Ctrl+C) и запустить заново. В выводе должна появиться строка процесса `reverb` о запуске сервера на `0.0.0.0:8080`.
- **Событие видно в DevTools → WS, но обработчик не срабатывает** — в `.listen()` нет точки в начале (`'message.created'` вместо `'.message.created'`) или имя не совпадает с `broadcastAs()`.
- **`POST /broadcasting/auth` отвечает 404** — в `bootstrap/app.php` нет строки `channels:`. Установщик пишет её сам, но если файл сильно отличается от стандартного, он может не справиться.
- **`POST /broadcasting/auth` отвечает 403 даже участнику** — шаблон в `routes/channels.php` не совпадает с именем канала (`chat.{chat}.messages` против `chats.5.messages`) или в имени написан префикс: `echo().private('private-chats.5.messages')`. Префикс добавляют сами `PrivateChannel` и `private()`.
- **`POST /broadcasting/auth` отвечает 500** — имя параметра в шаблоне не совпадает с аргументом функции (`{id}` и `Chat $chat`). Привязка модели ищет аргумент по имени.
- **Отправитель видит своё сообщение дважды** — нет `toOthers()` или браузер не отправляет `X-Socket-ID`. Во втором случае не задан `window.axios` или он задан после `configureEcho()`.
- **`Call to undefined method ...dontBroadcastToCurrentUser()`** — из события убран трейт `InteractsWithSockets`, а без него `toOthers()` не работает.
- **Под сообщением у собеседника «Аноним»** — `load('author')` стоит после `broadcast()` или его нет. Ловит `test_sent_message_is_broadcast_to_other_participants`.
- **Предупреждение `Attempting to mutate prop "messages"`** — в обработчике `this.messages.push(...)`, как на уроке. Дописывать нужно в `chatMessages`.
- **Сообщения чата приходят и на других страницах** — нет `echo().leave()` в `beforeUnmount()`. Подписка пережила уход со страницы.
- **Колокольчик вырос у того, кто поставил лайк** — в `broadcastOn()` взят `actor_id` вместо `profile_id`. Ловит `test_new_notification_is_broadcast_to_recipient`.
- **Событие уведомления пришло, а число не изменилось** — неверный путь в `replaceProp()`: `auth.user.notifications_count` вместо `auth.user.profile.notifications_count`.
- **`Cannot read properties of null` при выходе из аккаунта** — имя канала вычисляется в `beforeUnmount()` из `$page`, а там уже пропсы новой страницы без пользователя. Имя нужно запомнить в `created()`.
- **После перехода на `ShouldBroadcast` колокольчик перестал расти, а уведомления в попапе серые** — воркер перечитал уведомление из базы, и обсервер `retrieved` пометил его прочитанным (раздел 15).
- **Повторный лайк не обновил колокольчик, а после перехода на другую страницу число даже уменьшилось** — это ошибка из 30-го урока, которую стало видно только с живым счётчиком. Когда лайк снимают и ставят снова, `firstOrCreate()` в `LikeObserver` сначала ищет старое непрочитанное уведомление. Найденное уведомление читается из базы, `retrieved` помечает его прочитанным, а новое не создаётся — значит, и события нет. Исправление вне задания урока: искать через `exists()`, который не создаёт моделей и не поднимает `retrieved`, и создавать уведомление, только если не нашлось.
- **`php artisan db:seed` идёт очень долго или засыпает лог ошибками** — сидеры создают лайки и комментарии, и на каждое уведомление обсервер пытается отправить событие. На время сидинга поставить в `.env` `BROADCAST_CONNECTION=null`, потом вернуть `reverb`.
- **Посторонний читает чужой чат** — канал объявлен публичным `Channel`, как на уроке. Нужен `PrivateChannel` и правило в `routes/channels.php` (раздел 4).
- **Тест «посторонний получает 403 на `/broadcasting/auth`» зелёный при любом правиле** — в тестах драйвер `null`, и он пускает всех. Правило канала проверяется вызовом функции (раздел 18).

## 21. Что можно сделать лучше

**Сообщения через очередь.** `ShouldBroadcast` вместо `ShouldBroadcastNow` у `SendMessageEvent`: ответ на отправку перестанет ждать Reverb, а сбой отправки останется в `failed_jobs`, а не только в логе. Для уведомлений сначала нужно заменить эксперимент с `retrieved` отдельным маршрутом «пометить прочитанными» (раздел 15).

**Прокрутка к новому сообщению.** Идея из 32-го урока теперь нужнее: сообщения появляются сами, и новое не должно оказываться за нижним краем ленты. Прокручивать вниз и после отправки, и в обработчике `.message.created`.

**«Печатает…».** У приватных каналов есть события, которые браузер отправляет другим браузерам напрямую, без запроса к Laravel: `echo().private(...).whisper('typing', {...})` у пишущего и `.listenForWhisper('typing', ...)` у собеседника. Reverb их поддерживает.

**Кто в сети.** `PresenceChannel` вместо `PrivateChannel` для чата: правило канала возвращает не `true`, а данные участника (`['id' => ..., 'nickname' => ...]`), и Echo сообщает, кто подключился и кто ушёл.

**Живой попап.** Если попап открыт в момент прихода уведомления, добавлять новую строку в начало списка. Для этого событию понадобятся текст и ссылка, а `NotificationResource` придётся научить свежесозданным уведомлениям.

**Состояние соединения.** Показывать «нет связи», когда Reverb недоступен. В `@laravel/echo-vue` для этого есть хук `useConnectionStatus()`, но он рассчитан на Composition API.

**Reverb на проде.** Держать `reverb:start` запущенным через Supervisor (как воркер очереди, 29-й урок) и отдавать веб-сокет через Nginx по `wss://`. Либо переключиться на Pusher и не поднимать ничего своего.

**Классы каналов.** Когда правил в `routes/channels.php` станет много, их можно вынести в классы: `php artisan make:channel ChatChannel`, метод `join()` вместо функции. Проверять их можно так же, как политики.

**Исправить дедупликацию лайков.** Заменить в `LikeObserver` `firstOrCreate()` на проверку через `exists()` (раздел 20), чтобы повторный лайк не помечал старое уведомление прочитанным.

## Задание

ВЕБ-СОКЕТЫ

1. Сделать появление сообщений от собеседника через websocket.
2. Сделать оповещения о лайках и комментариях через websocket.

В конспекте расписать бродкаст через pusher.com, в коде реализовать бродкаст через Reverb.

- `php artisan install:broadcasting`
- `php artisan make:event WS/SendMessageEvent`

**Решения, принятые при подготовке урока:**

- получатель уведомления видит только живой счётчик на колокольчике; список по-прежнему подгружается по клику;
- событие отправляется для всех уведомлений (комментарии, лайки и репосты) из одного места — `NotificationObserver::created()`;
- оба канала приватные, хотя на уроке канал чата публичный (раздел 4).
