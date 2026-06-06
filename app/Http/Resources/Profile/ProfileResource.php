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
        ];
    }
}
