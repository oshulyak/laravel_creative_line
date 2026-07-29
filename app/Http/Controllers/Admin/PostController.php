<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Post\PostResource;
use App\Models\Post;
use Inertia\Response;

class PostController extends Controller {
    /**
     * Список постов для админки.
     *
     * Вместо view() возвращаем Inertia-страницу: первый аргумент — путь к компоненту
     * относительно resources/js/Pages/, второй — props, которые получит компонент.
     */
    public function index(): Response {
        $posts = Post::query()
            ->with('category')
            ->latest('id')
            ->get();

        return inertia('Admin/Post/Index', [
            'posts' => PostResource::collection($posts)->resolve(),
            'statuses' => Post::getStatuses(),
        ]);
    }
}
