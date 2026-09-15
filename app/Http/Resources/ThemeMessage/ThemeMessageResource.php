<?php

namespace App\Http\Resources\ThemeMessage;

use App\Http\Resources\Profile\ProfileSummaryResource;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ThemeMessageResource extends JsonResource {
    /**
     * Сообщение для ленты темы (ItemThemeMessage.vue).
     *
     * Как и в MessageResource, здесь нет ничего, что зависит от смотрящего:
     * JSON сообщения одинаков для всех, кто открыл тему.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'author_id' => $this->author_id,
            'content' => $this->content,
            // В JSON уйдёт строкой ISO-8601 в UTC, в местное время её переведёт браузер.
            'created_at' => $this->created_at,
            // whenLoaded: ключ появится, только если автор загружен, —
            // ресурс не сделает лишний запрос на каждое сообщение.
            'author' => $this->whenLoaded(
                'author',
                fn (Profile $author): array => ProfileSummaryResource::make($author)->resolve(),
            ),
        ];
    }
}
