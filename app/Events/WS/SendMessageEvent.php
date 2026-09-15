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
     * Правило доступа к каналу — в routes/channels.php.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array {
        return [
            new PrivateChannel('chats.'.$this->message->chat_id.'.messages'),
        ];
    }

    /**
     * Имя события. Во Vue его слушают с точкой в начале: .message.created —
     * точка говорит Echo не подставлять перед именем App.Events.
     */
    public function broadcastAs(): string {
        return 'message.created';
    }

    /**
     * Данные для браузера — тот же MessageResource, что у маппера
     * и у ответа на отправку.
     *
     * Без этого метода Laravel отправил бы модель через toArray(): со всем,
     * что в ней сейчас лежит, включая загруженного автора целиком.
     * Ресурс задаёт состав данных явно.
     *
     * @return array{message: array<string, mixed>}
     */
    public function broadcastWith(): array {
        return [
            'message' => MessageResource::make($this->message)->resolve(),
        ];
    }
}
