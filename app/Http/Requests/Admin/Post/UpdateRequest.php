<?php

namespace App\Http\Requests\Admin\Post;

use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest {
    /**
     * Как и в StoreRequest: генератор ставит false, полноценная проверка прав
     * появится вместе с ролями и политиками.
     */
    public function authorize(): bool {
        return true;
    }

    /**
     * Правила почти повторяют StoreRequest, но с двумя отличиями: уникальность title
     * игнорирует сам редактируемый пост, и добавлен список картинок на удаление.
     *
     * author_id и published_at здесь не принимаются вовсе: автор поста не меняется,
     * дата публикации проставлена при создании. Чего нет в правилах — того не будет
     * и в validated(), а значит, и в update().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // ignore() исключает текущую запись из проверки: без него пост,
            // сохранённый без смены заголовка, считался бы дублем самого себя.
            // Передаём модель — Rule сам возьмёт из неё первичный ключ.
            'title' => ['required', 'string', 'max:255', Rule::unique('posts', 'title')->ignore($this->route('post'))],
            'content' => ['required', 'string'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'deleted_images' => ['nullable', 'array'],
            // Проверяем не просто существование картинки, а её принадлежность
            // этому посту: иначе по чужому id можно было бы стереть картинку
            // из соседней публикации (классический IDOR).
            'deleted_images.*' => [
                'integer',
                Rule::exists('images', 'id')
                    ->where('imageable_type', Post::class)
                    ->where('imageable_id', $this->route('post')->id),
            ],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }

    /**
     * Здесь prepareForValidation() решает только вторую свою задачу — нормализацию:
     * строка тегов приводится к массиву. Подмешивать author_id и published_at,
     * как это делает StoreRequest, нельзя — они относятся к моменту создания.
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'tags' => $this->tagTitles(),
        ]);
    }

    /**
     * "laravel, vue , laravel" -> ['laravel', 'vue'].
     *
     * @return list<string>
     */
    private function tagTitles(): array {
        return collect(explode(',', (string) $this->input('tags')))
            ->map(fn (string $title): string => trim($title))
            ->filter(fn (string $title): bool => $title !== '')
            ->unique()
            ->values()
            ->all();
    }
}
