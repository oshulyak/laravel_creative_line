<?php

namespace App\Http\Requests\Api\Post;

use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest {
    /**
     * Determine if the user is authorized to make this request.
     */
    //   public function authorize(): bool {
    //       return false;
    //   }

    /**
     * Правила валидации соответствуют ограничениям колонок в миграции posts.
     * Уникальность title игнорирует текущий пост, чтобы при сохранении без
     * смены заголовка он не считался дублем самого себя.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            'author_id' => ['required', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255', Rule::unique('posts', 'title')->ignore($this->route('post'))],
            'content' => ['required', 'string'],
            'img_path' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'integer', Rule::in(array_keys(Post::getStatuses()))],
            'published_at' => ['nullable', 'date'],
        ];
    }
}
