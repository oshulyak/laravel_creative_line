<?php

namespace App\Http\Requests\Client\Group;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Создать группу может только пользователь с профилем: создатель
     * становится участником, а участники группы — профили.
     *
     * Без этой проверки GroupService получил бы null вместо профиля
     * и упал с 500.
     */
    public function authorize(): bool {
        return $this->user()->profile !== null;
    }

    /**
     * Из окна приходят title и description.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // max:255 — string() без длины в миграции.
            'title' => ['required', 'string', 'max:255'],
            // Пустое поле из формы придёт как null: пустые строки превращает
            // в null middleware ConvertEmptyStringsToNull. Поэтому nullable.
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
