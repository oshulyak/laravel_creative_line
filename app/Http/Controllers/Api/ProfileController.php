<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Profile\StoreRequest;
use App\Http\Requests\Api\Profile\UpdateRequest;
use App\Http\Resources\Profile\ProfileResource;
use App\Models\Profile;
use Illuminate\Http\Response;

class ProfileController extends Controller {
    /**
     * Список всех профилей.
     */
    public function index(): array {
        return ProfileResource::collection(Profile::all())->resolve();
    }

    /**
     * Создание профиля из проверенных данных запроса.
     */
    public function store(StoreRequest $request): array {
        $profile = Profile::create($request->validated());

        return ProfileResource::make($profile)->resolve();
    }

    /**
     * Показ одного профиля (найден через route-model binding).
     */
    public function show(Profile $profile): array {
        return ProfileResource::make($profile)->resolve();
    }

    /**
     * Обновление профиля проверенными данными запроса.
     */
    public function update(UpdateRequest $request, Profile $profile): array {
        $profile->update($request->validated());

        return ProfileResource::make($profile)->resolve();
    }

    /**
     * Удаление профиля.
     */
    public function destroy(Profile $profile): Response {
        $profile->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
