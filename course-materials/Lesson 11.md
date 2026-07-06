# Lesson 11 - Filters in Laravel

Фильтр нужен для `index`, когда список моделей нужно получать не целиком, а по query parameters:

`GET /api/posts?author_id=1&title=laravel&published_at_from=2026-01-01 00:00:00`

В этом уроке фильтруем `Post`.
Поля в примерах приведены под текущую схему проекта: `author_id`, `category_id`, `title`, `content`, `status`, `published_at`.

Artisan commands:
```
php artisan make:request Api/Post/IndexRequest
php artisan make:class Http/Filters/PostFilter
php artisan make:class Http/Filters/AbstractFilter
php artisan make:trait Models/Traits/HasFilter
```

## 1. Ручное создание фильтра

Сначала создаем Request для валидации query parameters.

IN `app/Http/Requests/Api/Post/IndexRequest.php`:
```php
namespace App\Http\Requests\Api\Post;

use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest {
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            'author_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
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

В ручном варианте вся логика фильтра находится прямо в `index()`.

IN `app/Http/Controllers/Api/PostController.php`:
```php
use App\Http\Requests\Api\Post\IndexRequest;
use Illuminate\Database\Eloquent\Builder;

public function index(IndexRequest $request): array {
    $data = $request->validated();

    $posts = Post::query();

    if (isset($data['author_id'])) {
        $posts->where('author_id', $data['author_id']);
    }

    if (isset($data['category_id'])) {
        $posts->where('category_id', $data['category_id']);
    }

    if (isset($data['title'])) {
        $value = $data['title'];
        $posts->where('title', 'ilike', "%{$value}%");
    }

    if (isset($data['content'])) {
        $value = $data['content'];
        $posts->where('content', 'ilike', "%{$value}%");
    }

    if (isset($data['category_title'])) {
        $value = $data['category_title'];

        $posts->whereHas('category', function (Builder $builder) use ($value): void {
            $builder->where('title', 'ilike', "%{$value}%");
        });

        // Short variant:
        // $posts->whereRelation('category', 'title', 'ilike', "%{$value}%");
    }

    if (isset($data['status'])) {
        $posts->where('status', $data['status']);
    }

    if (isset($data['published_at_from'])) {
        $posts->where('published_at', '>=', $data['published_at_from']);
    }

    if (isset($data['published_at_to'])) {
        $posts->where('published_at', '<=', $data['published_at_to']);
    }

    return PostResource::collection($posts->get())->resolve();
}
```

`ilike` используется потому, что в проекте PostgreSQL. Для MySQL обычно используют `like`.

Минус ручного варианта: контроллер быстро разрастается. Если фильтров много, лучше вынести их в отдельный класс.

## 2. Абстрактный фильтр через trait

Идея: контроллер только получает данные и вызывает фильтр, а сами условия лежат в отдельном классе.

`AbstractFilter` содержит общий алгоритм:
- пройтись по разрешенным ключам;
- проверить, пришел ли такой ключ в request;
- превратить `published_at_from` в `publishedAtFrom`;
- вызвать одноименный метод фильтра.

IN `app/Http/Filters/AbstractFilter.php`:
```php
namespace App\Http\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

abstract class AbstractFilter {
    /**
     * @var list<string>
     */
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

IN `app/Http/Filters/PostFilter.php`:
```php
namespace App\Http\Filters;

use Illuminate\Database\Eloquent\Builder;

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

Trait добавляет в модель переиспользуемый Eloquent scope.
Он сам определяет класс фильтра по имени модели: для `Post` будет использован `App\Http\Filters\PostFilter`.

IN `app/Models/Traits/HasFilter.php`:
```php
namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasFilter {
    public function scopeFilter(Builder $builder, array $data): Builder {
        $className = 'App\\Http\\Filters\\'.class_basename($this).'Filter';

        return (new $className())->apply($builder, $data);
    }
}
```

Подключаем trait в модели.

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

Итоговый request остается таким же:
`GET /api/posts?category_title=php&title=laravel&published_at_from=2026-01-01 00:00:00`
