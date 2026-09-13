<?php

namespace App\Http\Resources\Profile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource {
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'nickname' => $this->nickname,
            'first_name' => $this->first_name,
            'second_name' => $this->second_name,
            'img_path' => $this->img_path,
            'birth_date' => $this->birth_date,
            'gender' => $this->gender,
            'city' => $this->city,
            // Подписан ли текущий пользователь на этот профиль.
            //
            // whenHas — «отдай ключ, только если такой атрибут у модели есть».
            // Атрибут появляется от loadExists() в ProfileController::show();
            // там, где ресурс отдаёт автора внутри карточки поста, его нет,
            // и ключа в JSON не будет.
            //
            // Приведение к bool — по той же причине, что у is_liked в PostResource.
            'is_subscribed' => $this->whenHas('is_subscribed', fn (mixed $value): bool => (bool) $value),
            // Есть ли смысл показывать кнопку подписки: на собственный профиль
            // подписаться нельзя.
            //
            // Ключ отдаётся всегда: это дешёвое вычисленное булево, как can_delete
            // у поста. И так же, как can_delete, — подсказка интерфейсу, а не защита:
            // настоящая проверка стоит в toggleSubscribe().
            'can_subscribe' => $this->id !== $request->user()?->profile?->id,
        ];
    }
}
