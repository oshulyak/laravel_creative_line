<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Tag\StoreRequest;
use App\Http\Requests\Api\Tag\UpdateRequest;
use App\Http\Resources\Tag\TagResource;
use App\Models\Tag;
use Illuminate\Http\Response;

class TagController extends Controller {
    /**
     * Список всех тегов.
     */
    public function index(): array {
        return TagResource::collection(Tag::all())->resolve();
    }

    /**
     * Создание тега из проверенных данных запроса.
     */
    public function store(StoreRequest $request): array {
        $tag = Tag::create($request->validated());

        return TagResource::make($tag)->resolve();
    }

    /**
     * Показ одного тега (найден через route-model binding).
     */
    public function show(Tag $tag): array {
        return TagResource::make($tag)->resolve();
    }

    /**
     * Обновление тега проверенными данными запроса.
     */
    public function update(UpdateRequest $request, Tag $tag): array {
        $tag->update($request->validated());

        return TagResource::make($tag)->resolve();
    }

    /**
     * Удаление тега.
     */
    public function destroy(Tag $tag): Response {
        $tag->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
