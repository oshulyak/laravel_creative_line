<?php

namespace App\Http\Controllers;

use App\Models\Like;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LikeController extends Controller {
    public function store(Request $request): Like {
        return Like::create();
    }

    public function destroy(Like $like): Response {
        $like->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
