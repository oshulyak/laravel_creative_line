<?php

namespace App\Http\Requests\Client\Theme;

use App\Models\Group;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Создавать темы могут только участники группы.
     *
     * Проверка в authorize(), как в Message\StoreRequest: посторонний
     * получит 403, не дойдя до валидации. Само правило живёт в модели.
     *
     * $this->route('group') — та же модель, что приедет в контроллер:
     * привязка выполняется до Form Request. Несуществующая группа сюда
     * не дойдёт — привязка вернёт 404 раньше.
     */
    public function authorize(): bool {
        /** @var Group $group */
        $group = $this->route('group');

        return $group->hasSubscriber($this->user()->profile);
    }

    /**
     * Из формы приходит только title, author_id подставляет prepareForValidation().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            'title' => ['required', 'string', 'max:255'],
            // exists не нужен: id берётся из профиля, уже загруженного из базы.
            // required остаётся: у пользователя без профиля здесь null, и это 422.
            'author_id' => ['required', 'integer'],
        ];
    }

    /**
     * Автор темы — профиль из сессии. merge() перетирает author_id,
     * даже если клиент прислал его сам.
     *
     * group_id здесь нет: его подставит связь $group->themes()->create().
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'author_id' => $this->user()?->profile?->id,
        ]);
    }
}
