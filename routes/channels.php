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
// Имя в скобках и имя аргумента должны совпадать, иначе привязка не сработает.
//
// Имя канала пишется без префикса private-: его добавляют PrivateChannel
// в событии и echo().private() во Vue.
//
// Пример App.Models.User.{id} из заготовки удалён: это канал встроенных
// уведомлений Laravel, а в проекте уведомления свои (30-й урок).

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
