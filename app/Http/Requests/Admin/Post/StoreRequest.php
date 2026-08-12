<?php

namespace App\Http\Requests\Admin\Post;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Генератор ставит здесь false — это топ-1 причина внезапного 403 при отправке формы.
     * Полноценная проверка прав появится вместе с ролями и политиками.
     */
    public function authorize(): bool {
        return true;
    }

    /**
     * Правила берутся из схемы posts, а не из головы: title — NOT NULL и unique,
     * content — NOT NULL, published_at — nullable.
     *
     * Отдельный неймспейс Admin нужен потому, что Api\Post\StoreRequest уже есть:
     * у админки свой набор правил (например, автора она не принимает из формы).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            'title' => ['required', 'string', 'max:255', 'unique:posts,title'],
            'content' => ['required', 'string'],
            // Мягкое date, а не date_format: <input type="datetime-local">
            // присылает строку вида 2026-08-10T12:30, без секунд.
            'published_at' => ['nullable', 'date'],
        ];
    }
}
