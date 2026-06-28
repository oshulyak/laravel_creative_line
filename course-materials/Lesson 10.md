# Lesson 10 - Logging, Exceptions
## Logging
`storage/logs/laravel.log` - main laravel log

`config/logging.php`:
```php
// Available drivers: "single", "daily", "slack", "syslog", "errorlog", "monolog", "custom", "stack"

'stack' => [	// Default channel
    'driver' => 'stack',
    'channels' => explode(',', (string) env('LOG_STACK', 'single')), // Can write to multi channels defined in .env
    'ignore_exceptions' => false,
],
'single' => [	// Log to single file. Used by default via `stack`
    'driver' => 'single',
    'path' => storage_path('logs/laravel.log'),
    'level' => env('LOG_LEVEL', 'debug'), // Min level of messages to write
    'replace_placeholders' => true,
],
'daily' => [	// Separate file for each day, keep 14 days
    'driver' => 'daily',
    'path' => storage_path('logs/laravel.log'),  // converts filename to `laravel-2026-06-24.log`
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => env('LOG_DAILY_DAYS', 14),
    'replace_placeholders' => true,
],
'post' => [		// Custom channel with 'single' driver
    'driver' => 'single',
    'path' => storage_path('logs/post.log'),
    'replace_placeholders' => true,
    'tap' => [PostLogFormatter::class], // see below ↓
]
```
`php artisan make:class LogFormatters/PostLogFormatter`:
```php
    public function __invoke(Logger $logger): void{
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new LineFormatter(
                '[%datetime%] %channel%.%level_name%: %message% %context% %extra%'.PHP_EOL
            ));
        }
    }
```

Write to log from anywhere:
```php
	Log::channel('post')->info('Post created wtih id {id}', ['post'=>$post, 'id'=>$post->id]);
```

*Log messages levels:*
1. emergency
2. alert
3. critical
4. error
5. warning
6. notice
7. info
8. debug


## Exceptions
`php artisam make:exception PostException --render --report`:
```php
class PostException extends Exception
{
    public function __construct(
        public Post $post,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Report the exception.
     */
    public function report(): void
    {
        Log::channel('post')->info('this post with {id} exists', ['id' => $this->post->id]);
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): Response
    {
        return \response([
            'message' => $this->getMessage()
        ], $this->getCode());
    }

    public static function isPostExists(Post $post): void
    {
        if (! $post->wasRecentlyCreated) {
            throw new self($post, message: '333', code: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
```

in Api/PostController:
```php
   public function show(Post $post): array {
		PostException::isPostExists($post); // PostException throws itself if Post was not exist
        return PostResource::make($post)->resolve();

    }	
```

### Comment to example
Это учебный пример, который показывает, что Laravel может автоматически вызвать `report()` и `render()` у выброшенного исключения. Для реального кода такой вариант лучше оформить аккуратнее:

1. Назвать исключение по конкретной ошибке, например `PostAlreadyExistsException`, а не общо `PostException`.
2. Не называть метод `isPostExists()`, потому что методы с `is...` обычно возвращают `true`/`false`. Если метод бросает исключение, лучше назвать его `throwIfAlreadyExists()` или `assertWasRecentlyCreated()`.
3. Не держать бизнес-проверку внутри класса исключения. Исключение должно описывать ошибочную ситуацию, а проверку лучше выполнять в контроллере, action/service-классе или рядом с кодом, который создает пост.
4. `try/catch` здесь не нужен: если исключение не поймано вручную, Laravel поймает его на верхнем уровне обработки HTTP-запроса, вызовет `report()` для логирования и `render()` для формирования ответа.

Более правильная идея:
```php
if (! $post->wasRecentlyCreated) {
    throw new PostAlreadyExistsException($post);
}
```
А внутри `PostAlreadyExistsException` уже можно оставить только данные исключения, `report()` и `render()`.


## Homework
Удалить модель `app/Models/Log.php` и отказаться от логирования модельных событий в БД.
Отказаться от отдельного механизма логирования для `Post` через observer, реализованного в коммите `3a8e8ce`: убрать `PostObserver` и его подключение в `Post`.

Логирование для всех моделей приложения организовать через трейт `HasLog.php`.

В `HasLog` зарегистрировать обработчики событий `created`, `updated`, `deleted`, `retrieved`.
Регистрацию выполнить в `bootHasLog()`.

При срабатывании события динамически создавать on-demand log channel через `Log::build()`
и записывать сообщение в файл:
`storage/logs/{model}/{event}.log`

Например:
`storage/logs/post/created.log`

Для канала использовать кастомный formatter через `tap`.

Что записывать в лог:
- при создании модели — id и атрибуты, с которыми модель создана;
- при обновлении — id и изменённые атрибуты;
- при чтении и удалении — только id модели.

