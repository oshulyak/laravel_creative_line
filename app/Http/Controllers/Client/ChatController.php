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
        // 403, а не 404: номера чатов идут подряд, и скрывать сам факт
        // существования чата незачем. Когда в курсе появятся политики, проверка
        // переедет в ChatPolicy::view(), пока она живёт в контроллере — осознанно.
        //
        // Если у пользователя нет профиля, в contains() уйдёт null: среди
        // участников он не найдётся, и ответ будет тем же 403.
        abort_unless($chat->profiles->contains($request->user()->profile), 403);

        return inertia('Client/Chat/Show', [
            'chat' => ChatResource::make($chat)->resolve(),
        ]);
    }
}
