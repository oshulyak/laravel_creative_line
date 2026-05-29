<?php

namespace App\Http\Resources\Post;

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
        ];
    }
}
