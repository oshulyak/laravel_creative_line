<?php

namespace App\Mappers;

use App\Http\Resources\Chat\ChatResource;
use App\Http\Resources\Message\MessageResource;
use App\Models\Chat;
use App\Models\Profile;

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
class ChatMapper {
    /**
     * Пропсы страницы списка чатов.
     *
     * Список без пагинации — осознанное упрощение, как и лента сообщений в show().
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
        //
        // Все сообщения без пагинации — осознанное упрощение учебного чата.
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
            // Участники внутри chat, как в 31-м уроке: из них строится заголовок.
            'chat' => ChatResource::make($chat)->resolve(),
            'messages' => MessageResource::collection($messages)->resolve(),
        ];
    }
}
