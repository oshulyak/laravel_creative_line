<?php

namespace App\Http\Resources\Theme;

use App\Http\Resources\Group\GroupResource;
use App\Http\Resources\Profile\ProfileSummaryResource;
use App\Models\Group;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ThemeResource extends JsonResource {
    /**
     * Тема для списка в группе и для шапки страницы темы.
     *
     * Вложенное и посчитанное отдаётся, только если его загрузил маппер:
     * в списке — автор и число сообщений, на странице темы — автор и группа.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'created_at' => $this->created_at,
            'messages_count' => $this->whenCounted('messages'),
            // ProfileSummaryResource: флаги смотрящего автору темы не нужны.
            'author' => $this->whenLoaded(
                'author',
                fn (Profile $author): array => ProfileSummaryResource::make($author)->resolve(),
            ),
            'group' => $this->whenLoaded(
                'group',
                fn (Group $group): array => GroupResource::make($group)->resolve(),
            ),
        ];
    }
}
