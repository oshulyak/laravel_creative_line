<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UserController extends Controller {
    public function index(): Collection {
        return User::all();
    }

    public function store(Request $request): User {
        return User::create($request->all());
    }

    public function show(User $user): User {
        return $user;
    }

    public function update(Request $request, User $user): User {
        $user->update($request->all());

        return $user;
    }

    public function destroy(User $user): Response {
        $user->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
