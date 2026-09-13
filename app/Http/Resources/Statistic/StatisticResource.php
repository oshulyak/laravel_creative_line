<?php

namespace App\Http\Resources\Statistic;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StatisticResource extends JsonResource {
    /**
     * Transform the resource into an array.
     *
     * date отдаём строкой Y-m-d, а не Carbon: без явного формата дата уехала бы
     * в JSON как 2026-09-13T00:00:00.000000Z, и браузер со своим часовым поясом
     * показал бы её уже другим днём. У дня без времени часового пояса нет.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'posts_count' => $this->posts_count,
            'reposts_count' => $this->reposts_count,
            'comments_count' => $this->comments_count,
            'likes_count' => $this->likes_count,
            'views_count' => $this->views_count,
            'likes_to_views_ratio' => $this->likes_to_views_ratio,
            'likes_to_comments_ratio' => $this->likes_to_comments_ratio,
        ];
    }
}
