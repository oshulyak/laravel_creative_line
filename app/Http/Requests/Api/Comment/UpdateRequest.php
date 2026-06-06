<?php

namespace App\Http\Requests\Api\Comment;

use App\Models\Comment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest {
    /**
     * Determine if the user is authorized to make this request.
     */
    // public function authorize(): bool {
    //     return false;
    // }

    /**
     * Правила совпадают со StoreRequest: у комментария нет уникальных колонок.
     * Статусы по-прежнему сверяем со списком из модели Comment (getStatuses()).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            'author_id' => ['required', 'integer', 'min:1'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'content' => ['required', 'string'],
            'status' => ['required', 'string', Rule::in(array_keys(Comment::getStatuses()))],
            'published_at' => ['nullable', 'date'],
        ];
    }
}
