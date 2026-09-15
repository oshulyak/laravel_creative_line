<?php

namespace App\Http\Requests\Client\Chat;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Создать чат может только пользователь с профилем: участники чата — профили.
     *
     * Проверка здесь, а не abort_if() в контроллере: authorize() срабатывает
     * раньше правил. Иначе пользователь без профиля получил бы не 403,
     * а ошибку валидации про null в members.
     */
    public function authorize(): bool {
        return $this->user()->profile !== null;
    }

    /**
     * Из окна приходят title и members — id выбранных профилей.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // Название обязательно: по нему групповой чат отличается от диалога
            // (docblock модели Chat). max:255 — string() без длины в миграции.
            'title' => ['required', 'string', 'max:255'],
            // min:2 — создатель (его дописал prepareForValidation) и хотя бы
            // один выбранный участник.
            'members' => ['required', 'array', 'min:2'],
            // Правила для каждого элемента массива.
            // distinct — один профиль не попадёт в чат дважды: attach() повторы
            // не проверяет, а unique в chat_profile превратил бы их в 500.
            'members.*' => ['integer', 'distinct', 'exists:profiles,id'],
        ];
    }

    /**
     * Создатель чата — тоже участник.
     *
     * Из окна приходят только те, кого пригласили: себя в списке поиска нет.
     * Id создателя берём из сессии и дописываем к ним, как author_id
     * у сообщения и комментария.
     *
     * Дописываем, только если members уже массив. Всё остальное оставляем
     * как пришло: не пришёл вовсе — ответит required, пришла строка — array.
     * Приводить значение к массиву через (array) здесь нельзя: строка "7"
     * стала бы массивом ещё до валидации, и правило array её бы не заметило.
     */
    protected function prepareForValidation(): void {
        $members = $this->input('members');

        if (! is_array($members)) {
            return;
        }

        $this->merge([
            'members' => [...$members, $this->user()?->profile?->id],
        ]);
    }
}
