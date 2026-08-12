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
            // Дата больше не приходит из формы, её подставляет prepareForValidation(),
            // поэтому значение обязательное: пост публикуется в момент создания.
            'published_at' => ['required', 'date'],
            // exists нужен даже при внешнем ключе: без него несуществующий id
            // дойдёт до INSERT и станет 500-й от PostgreSQL вместо 422 с сообщением.
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'author_id' => ['required', 'integer', 'exists:profiles,id'],
            'images' => ['nullable', 'array'],
            // images.* — правило для каждого элемента массива. max для файлов считается
            // в килобайтах, то есть 2048 — это 2 МБ (в пределах лимитов php.ini).
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * Подмешиваем поля, которых нет в форме, до запуска валидации: значения приходят
     * не от клиента, но проверяются теми же правилами.
     *
     * Порядок работы FormRequest: authorize() → prepareForValidation() → rules() → validated(),
     * поэтому добавленные ключи уже существуют к моменту проверки и попадают в validated().
     *
     * Пост принадлежит не пользователю, а его профилю (users → profiles → posts),
     * отсюда ->profile->id, а не auth()->id(). Оператор ?-> оставляет разбор ситуации
     * «у пользователя нет профиля» валидации: правило required вернёт 422, а не 500.
     *
     * published_at — момент создания: пост публикуется сразу, выбора даты в форме нет.
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'author_id' => auth()->user()->profile?->id,
            'published_at' => now(),
        ]);
    }
}
