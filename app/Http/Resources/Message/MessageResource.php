<?php

namespace App\Http\Resources\Message;

use App\Http\Resources\Profile\ProfileSummaryResource;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource {
    /**
     * Сообщение для ленты чата.
     *
     * JSON сообщения одинаков для всех участников чата: в нём нет ничего,
     * что зависит от текущего пользователя, — ни на верхнем уровне,
     * ни во вложенном авторе.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            // По author_id клиент отличает свои сообщения от чужих (ItemMessage.vue).
            // Флага is_mine здесь нет намеренно: он зависел бы от смотрящего.
            'author_id' => $this->author_id,
            'content' => $this->content,
            // created_at Eloquent кастует в Carbon сам. В JSON он уйдёт
            // строкой ISO-8601 в UTC, в местное время её переведёт браузер.
            'created_at' => $this->created_at,
            // whenLoaded: ключ появится, только если связь загружена,
            // и ресурс не спровоцирует лишний запрос на каждое сообщение.
            //
            // ProfileSummaryResource, а не ProfileResource: тот считает
            // can_subscribe и can_message относительно смотрящего.
            'author' => $this->whenLoaded(
                'author',
                fn (Profile $author): array => ProfileSummaryResource::make($author)->resolve(),
            ),
        ];
    }
}
