# Lesson 13 - Filters
Фильтр нужен для `index`, когда список моделей нужно получать не целиком, а по query parameters: 
`GET /api/posts?author_id=1&title=laravel&published_at_from=2026-01-01 00:00:00`  

В этом уроке фильтруем `Post`.
Поля в примерах приведены под текущую схему проекта: `author_id`, `category_id`, `title`, `content`, `status`, `published_at`.

## 1. Ручное создание фильтра
Сначала создаем Request для валидации query parameters. 

`php artisan make:request Api/Post/IndexRequest`:
```php
class IndexRequest extends FormRequest {
    public function rules(): array {
        return [
            'author_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1', 'exists:categories,id'],
            'category_title' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['nullable', 'integer', Rule::in(array_keys(Post::getStatuses()))],
            'published_at_from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'published_at_to' => ['nullable', 'date_format:Y-m-d H:i:s', 'after_or_equal:published_at_from'],
        ];
    }
}
```

В ручном варианте вся логика фильтра находится прямо в `index()` класса `PostController`.
IN `app/Http/Controllers/Api/PostController.php`:
```php
use App\Http\Requests\Api\Post\IndexRequest;
use Illuminate\Database\Eloquent\Builder;
  
public function index(IndexRequest $request): array {
    $data = $request->validated();
    $query = Post::query(); 

    if (isset($data['author_id'])) {
        $query->where('author_id', $data['author_id']);
    }  

    if (isset($data['category_id'])) {
        $query->where('category_id', $data['category_id']);
    }  

    if (isset($data['title'])) {
        $value = $data['title'];
        $query->where('title', 'ilike', "%{$value}%");
    }  

    if (isset($data['content'])) {
        $value = $data['content'];
        $query->where('content', 'ilike', "%{$value}%");
    }  

    if (isset($data['category_title'])) {
        $value = $data['category_title'];
        $query->whereHas('category', function (Builder $builder) use ($value): void {
            $builder->where('title', 'ilike', "%{$value}%");
        });

        // Short variant:
        // $query->whereRelation('category', 'title', 'ilike', "%{$value}%");
    }  

    if (isset($data['status'])) {
        $query->where('status', $data['status']);
    }  

    if (isset($data['published_at_from'])) {
        $query->where('published_at', '>=', $data['published_at_from']);
    }  

    if (isset($data['published_at_to'])) {
        $query->where('published_at', '<=', $data['published_at_to']);
    }  

    return PostResource::collection($query->get())->resolve();
}
```

`ilike` используется для PostgreSQL, для MySQL используют `like`.
Минус ручного варианта: контроллер быстро разрастается. Если фильтров много, лучше вынести их в отдельный класс.

## 2. Абстрактный фильтр через trait

Идея: контроллер только получает данные и вызывает фильтр, а сами условия лежат в отдельном классе.  

`AbstractFilter` содержит общий алгоритм:
	1. пройтись по разрешенным ключам;
	2. проверить, пришел ли такой ключ в request;
	3. превратить `published_at_from` в `publishedAtFrom`;
	4. вызвать одноименный метод фильтра.  

`php artisan make:class Filters/AbstractFilter`:
```php
abstract class AbstractFilter {

    protected array $keys = [];

    public function apply(Builder $builder, array $data): Builder {
        foreach ($this->keys as $key) {
            if (isset($data[$key])) {
                $methodName = Str::camel($key);
                $this->{$methodName}($builder, $data[$key]);
            }
        }  
        return $builder;
    }
}
```

  
`PostFilter` содержит только фильтры для постов.  
`php artisan make:class Filters/PostFilter`:
```php
class PostFilter extends AbstractFilter {

    protected array $keys = [
        'author_id',
        'category_id',
        'category_title',
        'title',
        'content',
        'status',
        'published_at_from',
        'published_at_to',
    ];
  
    protected function authorId(Builder $builder, int $value): void {
        $builder->where('author_id', $value);
    }  

    protected function categoryId(Builder $builder, int $value): void {
        $builder->where('category_id', $value);
    }
  
    protected function categoryTitle(Builder $builder, string $value): void {
        $builder->whereRelation('category', 'title', 'ilike', "%{$value}%");
        
        // $builder->whereHas('category', function(Builder $b) use ($value) {
    	// 	return $b->where('title', 'ilike', "%$value%");
		// });
    }

    protected function title(Builder $builder, string $value): void {
        $builder->where('title', 'ilike', "%{$value}%");
    }

    protected function content(Builder $builder, string $value): void {
        $builder->where('content', 'ilike', "%{$value}%");
    }  

    protected function status(Builder $builder, int $value): void {
        $builder->where('status', $value);
    }  

    protected function publishedAtFrom(Builder $builder, string $value): void {
        $builder->where('published_at', '>=', $value);
    }

    protected function publishedAtTo(Builder $builder, string $value): void {
        $builder->where('published_at', '<=', $value);
    }
}
```

Важно:
- ключи в `$keys` должны иметь соответствующие методы;
- `category_title` вызывает `categoryTitle()`;
- методы фильтра `protected`, а не `private`, потому что `apply()` объявлен в родительском классе `AbstractFilter`.

О типах параметров. Query-параметры всегда приходят из запроса **строками**, а `validated()` их не приводит к нужному типу — он лишь проверяет по правилам (`integer` и т.п.). Поэтому в `authorId(Builder $builder, int $value)` в `$value` попадает строка `"1"`. Сейчас это работает только потому, что в файле нет `declare(strict_types=1)`: при нестрогой типизации PHP сам приводит `"1" → 1`. Если включить строгую типизацию, тот же вызов упадёт с `TypeError`. Чтобы фильтр работал и при `declare(strict_types=1)`, нужно приводить типы явно — например, объявить параметр как `int|string $value` и кастовать значение:
```php
protected function authorId(Builder $builder, int|string $value): void {
    $builder->where('author_id', (int) $value);
}
```
Для БД это не обязательно (PostgreSQL сравнит `author_id` и со строкой `'1'`), это именно про согласованность типов в PHP.


Trait добавляет в модель переиспользуемый Eloquent scope.
Он сам определяет класс фильтра по имени модели: для `Post` будет использован `App\Filters\PostFilter`.

`php artisan make:trait Models/Traits/HasFilter`:
```php
trait HasFilter {

    public function scopeFilter(Builder $builder, array $data): Builder {
        $className = 'App\\Filters\\'.class_basename($this).'Filter';
        return (new $className())->apply($builder, $data);
    }
}
```

  
Подключаем trait в модели
IN `app/Models/Post.php`:
```php
use App\Models\Traits\HasFilter;

class Post extends Model {
    use HasFactory, HasLog, HasFilter;
    // ...
}
```

Теперь контроллер становится коротким.
IN `app/Http/Controllers/Api/PostController.php`:
```php
use App\Http\Requests\Api\Post\IndexRequest;

public function index(IndexRequest $request): array {
    $posts = Post::query()
        ->filter($request->validated())
        ->get();
    return PostResource::collection($posts)->resolve();
}
```

## 3. Вложенный ресурс категории

Когда мы фильтруем посты по категории, в ответе удобно отдавать не только `category_id`, но и сам объект категории. Для этого внутри `PostResource` подключаем `CategoryResource` — ресурс вкладывается в ресурс.

IN `PostResource`:
```php
    public function toArray(Request $request): array {
        return [
            // ....
            'category' => CategoryResource::make($this->category)->resolve(),
        ];
    }
```

Пояснение:
- `$this->category` — это связь `Post::category()` (`belongsTo`), Eloquent сам подгружает связанную модель `Category`;
- `CategoryResource::make(...)` оборачивает эту модель в ресурс, а `->resolve()` превращает его в массив — так вложенная `category` попадёт в итоговый JSON как объект `{ "id": ..., "title": ... }`;
- в проекте принято звать `->resolve()` на верхнем уровне (см. `index()`), поэтому и здесь для единообразия оставляем `->resolve()`; при вложении ресурсов можно было бы обойтись и без него (`CategoryResource::make($this->category)`) — Laravel развернёт вложенный ресурс сам.

Осторожно с N+1. При выводе списка постов связь `category` подгружается лениво, отдельным запросом на каждый пост. Чтобы этого избежать, категорию стоит загрузить заранее (eager load) в контроллере:
```php
$posts = Post::query()
    ->filter($request->validated())
    ->with('category')
    ->get();
```

## Homework
- Сделать фильтр по всем аттрибутам для Post
- Продемонстрировать фильтрацию через Postman