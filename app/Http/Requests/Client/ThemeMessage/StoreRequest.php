<?php

namespace App\Http\Requests\Client\ThemeMessage;

use App\Models\Theme;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Писать в тему могут только участники её группы.
     *
     * То же правило из модели, что у создания темы, только до группы
     * идём через тему: $theme->group — один запрос, hasSubscriber() — ещё один.
     */
    public function authorize(): bool {
        /** @var Theme $theme */
        $theme = $this->route('theme');

        return $theme->group->hasSubscriber($this->user()->profile);
    }

    /**
     * Из формы приходит только content, author_id подставляет prepareForValidation().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // 2000 — как у сообщений чата и комментариев.
            'content' => ['required', 'string', 'max:2000'],
            // exists не нужен: id берётся из профиля, уже загруженного из базы.
            // required остаётся: у пользователя без профиля здесь null, и это 422.
            'author_id' => ['required', 'integer'],
        ];
    }

    /**
     * Автор — профиль из сессии. theme_id подставит $theme->messages()->create().
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'author_id' => $this->user()?->profile?->id,
        ]);
    }
}
