<?php

namespace App\Http\Requests\Api\Profile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Determine if the user is authorized to make this request.
     */
    // public function authorize(): bool {
    //     return false;
    // }

    /**
     * Правила валидации соответствуют ограничениям колонок в миграции profiles.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            'user_id' => ['required', 'integer', 'min:1'],
            'nickname' => ['required', 'string', 'max:255', 'unique:profiles,nickname'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'second_name' => ['nullable', 'string', 'max:255'],
            'img_path' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
        ];
    }
}
