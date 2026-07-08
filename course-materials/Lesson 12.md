# Lesson 12 - Roles, API calls from external service
## Roles
`php artisan make:model Role -m` // belongsToMany users
`php artisan make:migration create_role_user_table`

добавить роли в seeder

`php artisan make:middleware isAdminMiddleware`:
```php
	public function handle(Request $request, Closure $next): Response{
	        if (!auth()->user()->is_admin){
	            return response([
	                'message' => 'forbidden'
	            ], status: \Illuminate\Http\Response::HTTP_FORBIDDEN);
	        }
	        return $next($request);
	    }
```

IN api.php:
```php
Route::group(['middleware' => ['jwt.auth', isAdminMiddleware::class]], function () {
//...
```

IN User.php:
```php
	public function getIsAdminAttribute(): bool{ // автоматически превращается в user()->is_admin
        return $this->roles->contains('title', 'admin');
    }
```

## API calls from external service
Создать новый проект-клиент:
`create-project laravel/laravel line_client`

`php artisan make:model Post -m`, из аттрибутов добавить только один `title`

`CUSTOM ARTISAN COMMAND`:
```php
public function handle(){
	// dd(PostHttpClient::login());
    //dd(PostHttpClient::getPosts());   
    
    dd(PostHttpClient::make()->login()->getPosts());
    
}
```


`php artisan make:class HttpClients/PostHttpClient.php`:
```php

private string $token = '';

public function login(): PostHttpClient{
    $this->token = Http::post('http://127.0.0.1:8000/api/auth/login', [
		'email' => config('line.email'),
		'password' => config('line.password')
    ])['access_token'];
    return $this;
}

public  function getPosts(): array{
    $posts = Http::withToken($this->token)->get('http://127.0.0.1:8000/api/posts');
    return $posts->json();
}

public static function make(): PostHttpClient{
	return new self();
	
}

```

 /config/line.php:
```php
return [
    'email' => env('LINE_EMAIL'),
    'password' => env('LINE_PASSWORD'),
];
```


## Homework

В основном проекте `line`:
1. Создать роль админа
2. Ограничить операции с постами: разрешить их только для админа

В проекте-клиенте `line_client`:
1. Создать кастомную artisan команду и класс PostHttpClient для тестирования обращений к основному проекту
2. Добавить exceptions в PostHttpClient на случаи:
   - ошибка логина
   - недостаточно прав (пользователь - не админ и не имеет доступа к постам)
   - нет данных в getPosts()
3. Создать getCategories(). Вынести URL обращения в конфиг, использовать baseUrl
4. Создать в PostHttpClient полный CRUD для Post
5. Сделать так, чтобы логин выполнялся только если access токена ещё нет. Сделать refresh если token expired.