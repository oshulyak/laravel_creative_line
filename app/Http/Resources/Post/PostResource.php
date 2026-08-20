<?php

namespace App\Http\Resources\Post;

use App\Http\Resources\Category\CategoryResource;
use App\Http\Resources\Image\ImageResource;
use App\Http\Resources\Tag\TagResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource {
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'author_id' => $this->author_id,
            'category_id' => $this->category_id,
            'title' => $this->title,
            'content' => $this->content,
            'img_path' => $this->img_path,
            'status' => $this->status,
            'published_at' => $this->published_at,
            // whenLoaded: ключ появится в ответе, только если связь уже загружена, —
            // так ресурс не спровоцирует лишний запрос там, где связь не нужна.
            // Вложенный ресурс обязательно разворачиваем ->resolve(): иначе в массиве
            // останется объект ресурса, а Inertia развернёт его сама через toResponse()
            // и добавит обёртку data — на клиенте получится category.data.title.
            'category' => $this->whenLoaded(
                'category',
                fn (Category $category): array => CategoryResource::make($category)->resolve(),
            ),
            'images' => $this->whenLoaded(
                'images',
                fn (Collection $images): array => ImageResource::collection($images)->resolve(),
            ),
            'tags' => $this->whenLoaded(
                'tags',
                fn (Collection $tags): array => TagResource::collection($tags)->resolve(),
            ),
        ];
    }
}
