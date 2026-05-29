<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RoleController extends Controller {
    public function index(): Collection {
        return Role::all();
    }

    public function store(Request $request): Role {
        return Role::create($request->all());
    }

    public function show(Role $role): Role {
        return $role;
    }

    public function update(Request $request, Role $role): Role {
        $role->update($request->all());

        return $role;
    }

    public function destroy(Role $role): Response {
        $role->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
