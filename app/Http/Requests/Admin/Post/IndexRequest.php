<?php

namespace App\Http\Requests\Admin\Post;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest {
    /**
     * Генератор ставит здесь false — это топ-1 причина внезапного 403 при первом же заходе
     * на страницу списка. Полноценная проверка прав появится вместе с ролями и политиками.
     */
    public function authorize(): bool {
        return true;
    }

    /**
     * Три поля формы фильтра. Ровно три — контракт админки уже, чем у Api\Post\IndexRequest:
     * чего нет здесь, того не будет и в validated(), а значит, и в фильтре.
     *
     * Отдельный класс, а не переиспользование апишного: тот принимает 14 параметров
     * и требует дату с временем, форма шлёт три параметра и дату без времени. Общий класс
     * пришлось бы обвешивать условиями. PostFilter при этом остаётся один на двоих —
     * контракт задаёт Request, поведение — фильтр.
     *
     * Всё nullable, ничего required: пустая форма должна отдавать полный список, а не 422.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            // <input type="date"> шлёт «2026-06-01» — без времени, в отличие от API.
            'published_at_from' => ['nullable', 'date_format:Y-m-d'],
            // min:0, а не min:1: «лайков не меньше нуля» — валидный, пусть и бесполезный запрос.
            'likes_from' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
