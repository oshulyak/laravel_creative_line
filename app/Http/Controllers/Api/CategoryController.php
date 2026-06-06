<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Category\StoreRequest;
use App\Http\Requests\Api\Category\UpdateRequest;
use App\Http\Resources\Category\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Response;

class CategoryController extends Controller {
    /**
     * Список всех категорий.
     */
    public function index(): array {
        return CategoryResource::collection(Category::all())->resolve();
    }

    /**
     * Создание категории из проверенных данных запроса.
     */
    public function store(StoreRequest $request): array {
        $category = Category::create($request->validated());

        return CategoryResource::make($category)->resolve();
    }

    /**
     * Показ одной категории (найдена через route-model binding).
     */
    public function show(Category $category): array {
        return CategoryResource::make($category)->resolve();
    }

    /**
     * Обновление категории проверенными данными запроса.
     */
    public function update(UpdateRequest $request, Category $category): array {
        $category->update($request->validated());

        return CategoryResource::make($category)->resolve();
    }

    /**
     * Удаление категории.
     */
    public function destroy(Category $category): Response {
        $category->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
