<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\StoreRequest;
use App\Http\Resources\Category\CategoryResource;
use App\Http\Resources\Post\PostResource;
use App\Models\Category;
use App\Models\Post;
use App\Services\PostService;
use Inertia\Response;

class PostController extends Controller {
    /**
     * Список постов для админки.
     *
     * Вместо view() возвращаем Inertia-страницу: первый аргумент — путь к компоненту
     * относительно resources/js/Pages/, второй — props, которые получит компонент.
     */
    public function index(): Response {
        // images грузим заранее: без eager loading каждая строка таблицы дала бы
        // отдельный запрос (N+1), а whenLoaded в ресурсе просто не отдал бы связь.
        $posts = Post::query()
            ->with(['category', 'images'])
            ->latest('id')
            ->get();

        return inertia('Admin/Post/Index', [
            'posts' => PostResource::collection($posts)->resolve(),
        ]);
    }

    /**
     * Страница с формой создания поста.
     *
     * Категория — справочник в БД, поэтому список вариантов для <select> не хардкодится
     * в шаблоне, а уезжает пропсом. Через ресурс, а не Category::all(): CategoryResource
     * отдаёт только id и title — ровно то, что нужно для <option>, без служебных полей.
     */
    public function create(): Response {
        $categories = CategoryResource::collection(Category::all())->resolve();

        return inertia('Admin/Post/Create', compact('categories'));
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

        // Контроллер отвечает только за HTTP: валидированные данные → сервис → ресурс.
        // Как именно создаётся пост (файлы, связи), знает PostService — эта логика
        // понадобится ещё и API-контроллеру, и консольной команде.
        $post = PostService::store($data);

        return PostResource::make($post)->resolve();
    }
}
