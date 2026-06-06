<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Comment\StoreRequest;
use App\Http\Requests\Api\Comment\UpdateRequest;
use App\Http\Resources\Comment\CommentResource;
use App\Models\Comment;
use Illuminate\Http\Response;

class CommentController extends Controller {
    /**
     * Список всех комментариев.
     */
    public function index(): array {
        return CommentResource::collection(Comment::all())->resolve();
    }

    /**
     * Создание комментария из проверенных данных запроса.
     */
    public function store(StoreRequest $request): array {
        $comment = Comment::create($request->validated());

        return CommentResource::make($comment)->resolve();
    }

    /**
     * Показ одного комментария (найден через route-model binding).
     */
    public function show(Comment $comment): array {
        return CommentResource::make($comment)->resolve();
    }

    /**
     * Обновление комментария проверенными данными запроса.
     */
    public function update(UpdateRequest $request, Comment $comment): array {
        $comment->update($request->validated());

        return CommentResource::make($comment)->resolve();
    }

    /**
     * Удаление комментария.
     */
    public function destroy(Comment $comment): Response {
        $comment->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
