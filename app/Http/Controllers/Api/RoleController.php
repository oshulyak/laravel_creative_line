<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Role\StoreRequest;
use App\Http\Requests\Api\Role\UpdateRequest;
use App\Http\Resources\Role\RoleResource;
use App\Models\Role;
use Illuminate\Http\Response;

class RoleController extends Controller {
    /**
     * Список всех ролей.
     */
    public function index(): array {
        return RoleResource::collection(Role::all())->resolve();
    }

    /**
     * Создание роли из проверенных данных запроса.
     */
    public function store(StoreRequest $request): array {
        $role = Role::create($request->validated());

        return RoleResource::make($role)->resolve();
    }

    /**
     * Показ одной роли (найдена через route-model binding).
     */
    public function show(Role $role): array {
        return RoleResource::make($role)->resolve();
    }

    /**
     * Обновление роли проверенными данными запроса.
     */
    public function update(UpdateRequest $request, Role $role): array {
        $role->update($request->validated());

        return RoleResource::make($role)->resolve();
    }

    /**
     * Удаление роли.
     */
    public function destroy(Role $role): Response {
        $role->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
