<?php

namespace App\Http\Resources\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Визитка профиля: кто это, без данных о текущем пользователе.
 *
 * Отдельный ресурс, а не ProfileResource: тот считает can_subscribe
 * и can_message относительно смотрящего, и один профиль выглядит по-разному
 * для разных людей. Здесь только то, что одинаково для всех, — такой JSON
 * можно показать любому участнику чата.
 */
class ProfileSummaryResource extends JsonResource {
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            // Аватар — часть визитки. В ленте сообщений он пока не показывается.
            'img_path' => $this->img_path,
        ];
    }
}
