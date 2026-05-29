<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProfileController extends Controller {
    public function index(): Collection {
        return Profile::all();
    }

    public function store(Request $request): Profile {
        return Profile::create($request->all());
    }

    public function show(Profile $profile): Profile {
        return $profile;
    }

    public function update(Request $request, Profile $profile): Profile {
        $profile->update($request->all());

        return $profile;
    }

    public function destroy(Profile $profile): Response {
        $profile->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
