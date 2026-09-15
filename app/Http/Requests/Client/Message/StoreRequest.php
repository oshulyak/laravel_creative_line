<?php

namespace App\Http\Requests\Client\Message;

use App\Models\Chat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Писать в чат могут только его участники.
     *
     * Проверка здесь, а не в контроллере: authorize() срабатывает раньше
     * правил, и посторонний получит 403, не дойдя до валидации. В контроллере
     * она выполнилась бы уже после неё — пустой текст в чужой чат дал бы 422.
     *
     * Порядок работы Form Request: prepareForValidation() → authorize() → rules().
     * Для постороннего prepareForValidation() безвреден: он только подставляет
     * author_id в запрос, в базу ничего не пишет.
     *
     * $this->route('chat') — та же модель Chat, что приедет в контроллер:
     * неявная привязка выполняется до Form Request, повторного запроса нет.
     * Несуществующий чат сюда не дойдёт — привязка вернёт 404 раньше.
     */
    public function authorize(): bool {
        /** @var Chat $chat */
        $chat = $this->route('chat');

        return $chat->hasParticipant($this->user()->profile);
    }

    /**
     * Из формы приходит только content. author_id подставляет
     * prepareForValidation(), но правило у него настоящее: значение
     * от сервера тоже стоит проверить.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // Колонка text длину не ограничивает. 2000 — решение продукта,
            // как у комментария: поле без верхней границы — открытая дверь.
            'content' => ['required', 'string', 'max:2000'],
            'author_id' => ['required', 'integer', 'exists:profiles,id'],
        ];
    }

    /**
     * Автор сообщения — профиль из сессии.
     *
     * merge() перетирает author_id, даже если клиент прислал его сам:
     * написать от имени собеседника подменой поля не получится.
     *
     * chat_id здесь нет: его подставит связь $chat->messages()->create().
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'author_id' => $this->user()?->profile?->id,
        ]);
    }
}
