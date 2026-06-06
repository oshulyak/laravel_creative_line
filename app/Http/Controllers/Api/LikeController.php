<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Like\StoreRequest;
use App\Http\Resources\Like\LikeResource;
use App\Models\Like;
use Illuminate\Http\Response;

class LikeController extends Controller {
    /**
     * Постановка лайка.
     * У лайка пока нет собственных полей, поэтому создаётся пустая запись.
     */
    public function store(StoreRequest $request): array {
        $like = Like::create($request->validated());

        return LikeResource::make($like)->resolve();
    }

    /**
     * Снятие лайка.
     */
    public function destroy(Like $like): Response {
        $like->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
