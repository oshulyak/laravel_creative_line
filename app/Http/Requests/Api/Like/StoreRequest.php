<?php

namespace App\Http\Requests\Api\Like;

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
     * У лайка пока нет собственных полей (таблица likes — только id и timestamps),
     * поэтому правил валидации нет.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [];
    }
}
