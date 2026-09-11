<?php

namespace App\Http\Resources\Comment;

use App\Http\Resources\Profile\ProfileResource;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource {
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'author_id' => $this->author_id,
            // parent_id заменён парой commentable_*: колонки parent_id в таблице нет
            // с тех пор, как ветку ответов и привязку к посту слили в один morphs().
            'commentable_id' => $this->commentable_id,
            'commentable_type' => $this->commentable_type,
            'content' => $this->content,
            'status' => $this->status,
            'published_at' => $this->published_at,
            // Дальше — ровно те же три ключа, что у PostResource, и по тем же причинам.
            // Форма ответа для лайкаемой сущности получается одинаковой, и клиентская
            // кнопка лайка сможет работать и с постом, и с комментарием.
            'likes_count' => $this->whenCounted('likedByProfiles'),
            // whenCounted('replies') ищет атрибут replies_count — то есть имя
            // берётся из ПСЕВДОНИМА запроса (comments as replies_count), а не из
            // имени связи. Ключ условный: там, где withCount() не звали (например,
            // в ответе на создание комментария), его в JSON просто не будет,
            // и клиент подставит 0.
            'replies_count' => $this->whenCounted('replies'),
            'is_liked' => $this->whenHas('is_liked', fn (mixed $value): bool => (bool) $value),
            'author' => $this->whenLoaded(
                'author',
                fn (Profile $author): array => ProfileResource::make($author)->resolve(),
            ),
        ];
    }
}
