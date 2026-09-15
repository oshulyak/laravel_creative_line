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

    /**
     * Страница чата.
     *
     * Chat $chat — неявная привязка модели: несуществующий id даёт 404
     * до контроллера.
     */
    public function show(Request $request, Chat $chat): Response {
        // Чат видят только участники: адрес /chats/5 легко набрать руками.
        // У GET-страницы нет Form Request, поэтому проверка стоит здесь,
        // до сборки пропсов.
        //
        // 403, а не 404 — осознанный компромисс: по разнице ответов посторонний
        // узнает, что чат существует, но не увидит ни сообщений, ни участников.
        // Номера чатов идут подряд, и их количество в проекте не секрет.
        abort_unless($chat->hasParticipant($request->user()->profile), 403);

        // Из чего состоит страница, решает маппер. Контроллер проверяет доступ
        // и передаёт готовый набор пропсов в Inertia.
        return inertia('Client/Chat/Show', ChatMapper::show($chat));
    }

    /**
     * Создание группового чата.
     *
     * Создатель уже в members, название проверено: всё это сделал
     * StoreChatRequest. Контроллеру остаётся вызвать сервис и ответить.
     *
     * Редирект, а не JSON: форму отправляет router.post() из Inertia,
     * и по редиректу она сама откроет страницу нового чата.
     */
    public function store(StoreChatRequest $request): RedirectResponse {
        $chat = ChatService::storeGroup($request->validated());

        return redirect()->route('client.chats.show', $chat);
    }

    /**
     * Отправка сообщения в чат.
     *
     * Проверки доступа здесь нет: участие проверил StoreMessageRequest::authorize(),
     * посторонний до этого метода не дойдёт.
     *
     * Возвращает JSON, а не редирект: форма отправляет запрос через axios,
     * страница остаётся на месте, а новое сообщение дописывается в ленту.
     *
     * Отправитель получает сообщение ответом на запрос, остальные
     * участники — событием через веб-сокет.
     */
    public function storeMessage(StoreMessageRequest $request, Chat $chat): JsonResponse {
        // create() на связи hasMany сам заполнит chat_id,
        // author_id и content пришли из validated().
        $message = $chat->messages()->create($request->validated());

        // Ник автора нужен и ответу, и событию: оба отдают сообщение через
        // MessageResource. Поэтому load() стоит раньше broadcast().
        $message->load('author');

        // toOthers(): всем подписчикам канала, кроме вкладки, из которой пришёл
        // запрос (её узнают по заголовку X-Socket-ID). Она получит сообщение
        // ответом ниже, и копия из канала встала бы в её ленту второй раз.
        broadcast(new SendMessageEvent($message))->toOthers();

        // 201 Created и само сообщение в теле — как у комментария.
        // resolve() — плоский объект без обёртки data.
        return response()->json(MessageResource::make($message)->resolve(), 201);
    }
}
