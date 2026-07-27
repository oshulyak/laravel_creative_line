<?php

namespace App\Http\Requests\Api\Post;

use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest {
    /**
     * Determine if the user is authorized to make this request.
     */
    //    public function authorize(): bool {
    //        return false;
    //    }

    /**
     * Правила валидации query-параметров фильтра по всем атрибутам Post.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            'id' => ['nullable', 'integer', 'min:1'],
            'author_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1', 'exists:categories,id'],
            'category_title' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'img_path' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'integer', Rule::in(array_keys(Post::getStatuses()))],
            'published_at_from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'published_at_to' => ['nullable', 'date_format:Y-m-d H:i:s', 'after_or_equal:published_at_from'],
            'created_at_from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'created_at_to' => ['nullable', 'date_format:Y-m-d H:i:s', 'after_or_equal:created_at_from'],
            'updated_at_from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'updated_at_to' => ['nullable', 'date_format:Y-m-d H:i:s', 'after_or_equal:updated_at_from'],
        ];
    }
}
