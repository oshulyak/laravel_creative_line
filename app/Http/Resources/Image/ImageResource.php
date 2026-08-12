<?php

namespace App\Http\Resources\Image;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ImageResource extends JsonResource {
    /**
     * Transform the resource into an array.
     *
     * В БД лежит относительный путь (images/9x2k....jpg), а фронтенду нужен URL.
     * Склейку делает диск: Storage::disk('public')->url() вернёт /storage/images/9x2k....jpg
     * с учётом настроек config/filesystems.php. Клиенту не нужно знать про префикс.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'img_path' => $this->img_path,
            'url' => Storage::disk('public')->url($this->img_path),
        ];
    }
}
