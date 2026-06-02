<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Post\StoreRequest;
use App\Http\Requests\Api\Post\UpdateRequest;
use App\Http\Resources\Post\PostResource;
use App\Models\Post;
use Illuminate\Http\Response;

class PostController extends Controller {
    /**
     * Список всех постов.
     */
    public function index(): array {
        return PostResource::collection(Post::all())->resolve();
    }

    /**
     * Создание поста из проверенных данных запроса.
     */
    public function store(StoreRequest $request): array {
        $post = Post::create($request->validated());

        return PostResource::make($post)->resolve();
    }

    /**
     * Показ одного поста (найден через route-model binding).
     */
    public function show(Post $post): array {
        return PostResource::make($post)->resolve();
    }

    /**
     * Обновление поста проверенными данными запроса.
     */
    public function update(UpdateRequest $request, Post $post): array {
        $post->update($request->validated());

        return PostResource::make($post)->resolve();
    }

    /**
     * Удаление поста.
     */
    public function destroy(Post $post): Response {
        $post->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
