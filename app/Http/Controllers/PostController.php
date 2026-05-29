<?php

namespace App\Http\Controllers;

use App\Http\Resources\Post\PostResource;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PostController extends Controller {
    public function index(): array {
        return PostResource::collection(Post::all())->resolve();
    }

    public function store(Request $request): array {
        $post = Post::create([
            'author_id' => 1,
            'category_id' => 1,
            'title' => 'Тестовый пост',
            'content' => 'Тестовое содержимое поста.',
            'img_path' => 'posts/test.jpg',
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        return PostResource::make($post)->resolve();
    }

    public function show(Post $post): array {
        return PostResource::make($post)->resolve();
    }

    public function update(Request $request, Post $post): array {
        $post->update($request->all());

        return PostResource::make($post)->resolve();
    }

    public function destroy(Post $post): Response {
        $post->delete();

        return response([
            'message' => 'deleted',
        ], status: Response::HTTP_OK);
    }
}
