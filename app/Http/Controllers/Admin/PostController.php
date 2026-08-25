<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\IndexRequest;
use App\Http\Requests\Admin\Post\StoreRequest;
use App\Http\Requests\Admin\Post\UpdateRequest;
use App\Http\Resources\Category\CategoryResource;
use App\Http\Resources\Post\PostResource;
use App\Models\Category;
use App\Models\Post;
use App\Services\PostService;
use Inertia\Response;

class PostController extends Controller {
    /**
     * Список постов для админки — он же источник данных для фильтра.
     *
     * Один URL отдаёт два формата: Inertia-страницу при обычном переходе и голый JSON
     * при запросе от axios, когда меняются поля фильтра. Отдельный маршрут не нужен —
     * данные те же, отличается только упаковка ответа.
     *
     * @return array<int, array<string, mixed>>|Response
     */
    public function index(IndexRequest $request): array|Response {
        // filter() — scope из трейта HasFilter: он сам находит PostFilter по имени модели
        // и применяет только те ключи, которые пришли в validated(). Фильтр не привязан
        // к API — тот же механизм из 13-го урока работает и здесь.
        //
        // category грузим заранее: без eager loading каждая строка таблицы дала бы
        // отдельный запрос (N+1), а whenLoaded в ресурсе просто не отдал бы связь.
        // withCount добавляет к каждому посту liked_by_profiles_count одним подзапросом.
        $posts = Post::query()
            ->filter($request->validated())
            ->with('category')
            ->withCount('likedByProfiles')
            ->latest('id')
            ->get();

        $posts = PostResource::collection($posts)->resolve();

        // Запросы различает заголовок Accept: axios просит application/json,
        // Inertia — text/html. Проверку делаем до inertia(), иначе axios получит
        // HTML целой страницы вместо массива постов.
        //
        // Именно wantsJson(), а не expectsJson(): второй возвращает true для любого
        // XHR-запроса, а Inertia шлёт X-Requested-With — и страница бы сломалась.
        return $request->wantsJson() ? $posts : inertia('Admin/Post/Index', compact('posts'));
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
     * Страница просмотра одного поста.
     *
     * Тайп-хинт Post $post — неявная привязка модели (route model binding): Laravel сам
     * находит запись по сегменту {post} и отдаёт 404, если её нет. Имя параметра в роуте
     * и имя аргумента обязаны совпадать.
     *
     * Связи грузим через load(), а не with(): модель уже готова, запрос строил контейнер.
     * Без load() ключи category/images/tags молча исчезнут из ответа — их отдаёт
     * whenLoaded() в PostResource.
     */
    public function show(Post $post): Response {
        $post->load(['category', 'images', 'tags']);

        return inertia('Admin/Post/Show', [
            'post' => PostResource::make($post)->resolve(),
        ]);
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

    /**
     * Страница с формой редактирования поста.
     *
     * Это show() плюс справочники: та же модель из route model binding, тот же load(),
     * но форме нужны ещё и варианты для <select> — поэтому рядом уезжает categories.
     *
     * Связь category в форме не используется (<select> работает с category_id), но грузим:
     * PostResource отдаёт её через whenLoaded(), и без загрузки ключа на клиенте не будет.
     *
     * CategoryResource::collection(Category::all()) теперь встречается дважды — с create().
     * Выносить в приватный метод пока рано: два одинаковых вызова ещё не дублирование.
     */
    public function edit(Post $post): Response {
        $post->load(['category', 'images', 'tags']);

        return inertia('Admin/Post/Edit', [
            'post' => PostResource::make($post)->resolve(),
            'categories' => CategoryResource::collection(Category::all())->resolve(),
        ]);
    }

    /**
     * Сохранение отредактированного поста.
     *
     * Как и store(), возвращает JSON: форму отправляет axios, страница не перерисовывается,
     * переход на просмотр поста инициирует клиент через router.visit().
     *
     * Порядок аргументов роли не играет — контейнер разрешает их по типам, а не по позиции.
     * Конвенция Laravel: сначала запрос, потом модели из маршрута.
     *
     * @return array<string, mixed>
     */
    public function update(UpdateRequest $request, Post $post): array {
        $post = PostService::update($post, $request->validated());

        return PostResource::make($post)->resolve();
    }
}
