<?php

namespace App\Http\Resources\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Профиль для шапки: минимум полей плюс счётчик непрочитанных уведомлений.
 *
 * Отдельный ресурс, а не ключ в ProfileResource: тот отдаёт автора внутри
 * каждой карточки поста и каждого комментария, и счётчик там означал бы
 * COUNT на каждого автора в ленте — плюс чужое число уведомлений на виду.
 */
class ProfileWithNotificationsCountResource extends JsonResource {
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            // Такой колонки в таблице нет — её считает аксессор
            // Profile::notificationsCount(). Для ресурса разницы никакой.
            'notifications_count' => $this->notifications_count,
        ];
    }
}
