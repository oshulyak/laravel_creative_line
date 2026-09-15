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
        // chats() сужает выборку до чатов смотрящего, whereHas() оставляет
        // только те, где среди участников есть второй профиль.
        //
        // Не firstOrCreate(): он ищет по колонкам одной таблицы, а условие
        // «чат, где участвуют эти двое» лежит в chat_profile.
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

            // attach() с массивом id — один INSERT на обе строки chat_profile.
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
