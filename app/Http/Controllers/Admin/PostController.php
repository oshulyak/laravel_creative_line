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
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Response;

class PostController extends Controller {
    /**
     * Список постов для админки — он же источник данных для фильтра и пагинации.
     *
     * Один URL отдаёт два формата: Inertia-страницу при обычном переходе и голый JSON
     * при запросе от axios, когда меняются поля фильтра или номер страницы. Отдельный
     * маршрут не нужен — данные те же, отличается только упаковка ответа.
     */
    public function index(IndexRequest $request): array|Response {
        $data = $request->validated();

        // Ключ кэша: одно значение на один различимый набор параметров.
        //
        // serialize() превращает массив в строку без потерь — с типами и вложенностью,
        // в отличие от implode() или http_build_query(). md5() нужен не для безопасности,
        // а чтобы получить короткий ключ фиксированной длины: у ключа кэша есть
        // ограничения (у нас это первичный ключ таблицы cache).
        //
        // Порядок ключей в $data влияет на результат, и это безопасно только потому,
        // что массив приходит из validated(): его порядок задан кодом IndexRequest::rules()
        // и одинаков для всех запросов. Клади мы в serialize() сырой $request->all(),
        // порядок диктовал бы клиент, и ?page=2&per_page=5 дал бы не тот ключ,
        // что ?per_page=5&page=2.
        //
        // Префикс posts_index_ обязателен: голый md5(serialize($data)) — ключ без имени,
        // другой контроллер с той же схемой молча прочитал бы чужое значение.
        //
        // Версия из PostService — механизм инвалидации: тегов у драйвера database нет,
        // поэтому любое сохранение поста увеличивает счётчик, и все ключи со старой
        // версией просто перестают находиться (протухают они потом сами, по TTL).
        //
        // Чего в ключе нет — пользователя. Сейчас админский список одинаков для всех.
        // Но появится правило «автор видит только свои посты» — и первый зашедший
        // положит в кэш свою выборку, а второй прочитает её как свою.
        $cacheKey = 'posts_index_'.PostService::indexVersion().'_'.md5(serialize($data));

        // remember($key, $ttl, $callback) — это «get или посчитай и положи»: есть значение
        // по ключу — вернуть его и замыкание не выполнять; нет — выполнить, положить
        // на указанный срок, вернуть. TTL принимает и число секунд, и момент времени.
        //
        // Оборачиваем только поход в базу, а не весь экшен: валидация и ветка wantsJson()
        // остаются снаружи. Кэшировать нужно дорогую часть.
        //
        // В кэш уезжает ГОТОВЫЙ МАССИВ, а не пагинатор. Наивная версия
        //
        //     $posts = Cache::remember($cacheKey, now()->addMinutes(120), fn () => Post::query()->…->paginate(…));
        //
        // отработала бы ровно один раз. Первый запрос — промах кэша: замыкание вернуло
        // настоящий LengthAwarePaginator, страница отрисовалась. Второй запрос — попадание,
        // и он падает с «The script tried to call a method on an incomplete object».
        // Причина в config/cache.php: в Laravel 13 по умолчанию 'serializable_classes' => false,
        // то есть unserialize() вызывается с ['allowed_classes' => false] и возвращает
        // любой объект как __PHP_Incomplete_Class — заглушку без единого метода.
        // Сделано это против gadget chain: с утёкшим APP_KEY подложенное в кэш значение
        // позволило бы собрать цепочку вызовов из классов приложения.
        //
        // Лечится не белым списком классов (пришлось бы перечислить весь граф —
        // LengthAwarePaginator, Eloquent\Collection, Post, Category, Carbon, — и любой
        // новый with() его тихо ломает) и не значением true (это выключить защиту всему
        // приложению ради одного места), а тем, что в кэш кладутся данные, а не объекты.
        $posts = Cache::remember($cacheKey, now()->addMinutes(120), function () use ($data): array {
            // filter() — scope из трейта HasFilter: он сам находит PostFilter по имени модели
            // и применяет только те ключи, которые пришли в validated(). Фильтру достаётся
            // блок filters: группировка — это про контракт HTTP, сам PostFilter не изменился.
            //
            // ?? [] обязателен: у контейнера с правилом array и вложенными правилами
            // validated() возвращает только реально пришедшие вложенные ключи, а сам контейнер
            // пропускает. Пустая форма — и ключа filters в $data нет (подробности в IndexRequest).
            // У pagination той же проблемы нет: prepareForValidation() всегда пишет оба
            // вложенных ключа, поэтому блок в validated() всегда собирается.
            //
            // category грузим заранее: без eager loading каждая строка таблицы дала бы
            // отдельный запрос (N+1), а whenLoaded в ресурсе просто не отдал бы связь.
            // Пагинация N+1 не отменяет: пять строк дадут пять лишних запросов вместо тысячи —
            // меньше, но не «можно».
            //
            // latest('id') обязателен: offset без order by не даёт PostgreSQL никаких
            // гарантий порядка, и одна запись может приехать сразу на двух страницах.
            //
            // paginate() идёт последним — он не достраивает запрос, а выполняет его двумя
            // запросами (count(*) плюс limit/offset) и возвращает LengthAwarePaginator вместо
            // билдера. Аргументы: записей на странице, колонки, имя query-параметра и номер
            // страницы. Номер передаём явно, потому что он приезжает в pagination[page],
            // а встроенный резолвер Laravel читает только плоский ?page=.
            //
            // Весь этот код выполняется лениво: пока значение лежит в кэше, замыкание
            // не вызывается вообще — Post::query() внутри него просто не строится.
            $paginator = Post::query()
                ->filter($data['filters'] ?? [])
                ->with('category')
                ->withCount('likedByProfiles')
                ->latest('id')
                ->paginate($data['pagination']['per_page'], ['*'], 'page', $data['pagination']['page']);

            // Ресурс разворачиваем внутри замыкания: в кэш уезжает готовый массив
            // { data, links, meta } — ни одного объекта, ни одной модели, ни одного Carbon.
            // response()->getData(true) — это тот же ответ, что ушёл бы в браузер,
            // но разобранный обратно в ассоциативный массив.
            //
            // Побочная выгода: на попадании в кэш пропускается не только поход в базу,
            // но и работа PostResource — раньше он пересобирал массив на каждый запрос.
            return PostResource::collection($paginator)->response()->getData(true);
        });

        // Запросы различает заголовок Accept: axios просит application/json,
        // Inertia — text/html. Проверку делаем до inertia(), иначе axios получит
        // HTML целой страницы вместо данных.
        //
        // Именно wantsJson(), а не expectsJson(): второй возвращает true для любого
        // XHR-запроса, а Inertia шлёт X-Requested-With — и страница бы сломалась.
        //
        // Обе ветки получают одинаковый массив { data, links, meta }: JSON-ветке роутер
        // сам сделает из него JsonResponse, Inertia положит его пропсом.
        // Форма ответа не изменилась, Index.vue правок из-за кэша не требует.
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

    /**
     * Удаление поста.
     *
     * Алиас в импорте нужен из-за коллизии: в этом файле Response — это Inertia\Response,
     * его возвращают страницы. Здесь ответ обычный HTTP-шный.
     *
     * Route model binding работает и здесь: несуществующий id даст 404 от контейнера,
     * до контроллера дело не дойдёт, — проверять if (! $post) не нужно.
     *
     * 204 No Content: тела у ответа нет — клиенту нечего показывать, он и так знает,
     * какой пост удалял. Возвращать удалённую модель обратно — распространённая,
     * но бессмысленная привычка: этих данных больше не существует.
     *
     * Удалять посты может только администратор: всю группу /admin закрывает
     * middleware admin (routes/web.php). PostPolicy::delete() здесь не вызываем:
     * её правило «удаляет только автор» для админки не подходит — администратор
     * удаляет и чужие посты.
     */
    public function destroy(Post $post): HttpResponse {
        PostService::destroy($post);

        return response()->noContent();
    }
}
