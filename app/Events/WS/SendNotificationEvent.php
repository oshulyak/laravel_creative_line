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
 * ShouldBroadcastNow здесь обязателен, а не просто повторяет урок. Через
 * очередь воркер заново прочитал бы уведомление из базы, NotificationObserver
 * пометил бы его прочитанным на retrieved, и новое уведомление не попало бы
 * в число непрочитанных.
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
