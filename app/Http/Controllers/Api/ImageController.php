<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Image\StoreRequest;
use App\Http\Resources\Image\ImageResource;
use App\Models\Image;
use Illuminate\Http\Response;

class ImageController extends Controller {
    /**
     * Список всех изображений.
     */
    public function index(): array {
        return ImageResource::collection(Image::all())->resolve();
    }

    /**
     * Создание изображения из проверенных данных запроса.
     */
    public function store(StoreRequest $request): array {
        $image = Image::create($request->validated());

        return ImageResource::make($image)->resolve();
    }

    /**
     * Показ одного изображения (найдено через route-model binding).
     */
    public function show(Image $image): array {
        return ImageResource::make($image)->resolve();
    }

    /**
     * Удаление изображения.
     * Метода update нет намеренно: картинку перезагружают (store), а не правят.
     */
    public function destroy(Image $image): Response {
        $image->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
