<?php

namespace App\Http\Resources\Group;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource {
    /**
     * Группа для каталога, страницы группы и шапки темы.
     *
     * Число участников и признак «я в группе» приходят, только если их
     * посчитал запрос в маппере. В шапке темы счётчик не нужен, и ключа
     * subscribers_count там не будет.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            // whenCounted: ключ появится, только если был withCount()/loadCount().
            'subscribers_count' => $this->whenCounted('subscribers'),
            // whenHas: ключ появится, только если был withExists()/loadExists().
            // Приведение к bool — как у is_subscribed в ProfileResource.
            'is_subscribed' => $this->whenHas('is_subscribed', fn (mixed $value): bool => (bool) $value),
        ];
    }
}
