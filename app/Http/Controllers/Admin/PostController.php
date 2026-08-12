<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\StoreRequest;
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

    /**
     * Страница с формой создания поста.
     */
    public function create(): Response {
        return inertia('Admin/Post/Create');
    }

    /**
     * Сохранение поста из админки.
     *
     * Возвращаем JSON, а не Inertia-страницу: форму отправляет axios обычным XHR,
     * поэтому ответ прилетает в .then(), а страница не перерисовывается.
     *
     * @return array<string, mixed>
     */
    public function store(StoreRequest $request): array {
        $data = $request->validated();

        // posts.author_id — NOT NULL, а выбора автора в форме пока нет.
        // TODO: заменить на выбранного автора, когда дойдём до этой темы.
        $data['author_id'] = 1;

        $post = Post::create($data);

        return PostResource::make($post)->resolve();
    }
}
