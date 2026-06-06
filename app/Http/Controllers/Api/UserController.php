<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\User\StoreRequest;
use App\Http\Requests\Api\User\UpdateRequest;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use Illuminate\Http\Response;

class UserController extends Controller {
    /**
     * Список всех пользователей.
     */
    public function index(): array {
        return UserResource::collection(User::all())->resolve();
    }

    /**
     * Создание пользователя из проверенных данных запроса.
     * Пароль хешируется автоматически — за это отвечает каст 'password' => 'hashed' в модели User.
     */
    public function store(StoreRequest $request): array {
        $user = User::create($request->validated());

        return UserResource::make($user)->resolve();
    }

    /**
     * Показ одного пользователя (найден через route-model binding).
     */
    public function show(User $user): array {
        return UserResource::make($user)->resolve();
    }

    /**
     * Обновление пользователя проверенными данными запроса.
     */
    public function update(UpdateRequest $request, User $user): array {
        $user->update($request->validated());

        return UserResource::make($user)->resolve();
    }

    /**
     * Удаление пользователя.
     */
    public function destroy(User $user): Response {
        $user->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
