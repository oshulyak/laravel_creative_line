# Lesson 14 - Vue

Starter kit тянет много лишнего (shadcn), поэтому используем Breeze.

- `composer require laravel/breeze --dev`
- `php artisan breeze:install vue`
- `npm install`
- `php artisan serve`
- `vite`

Установка затирает: `app\Providers\AppServiceProvider.php`, `routes\web.php`

Inertia позволяет пользоваться роутингом бэкенда.

Добавились:
- `resources/js`
	  `resources/js/Components/`
	  `resources/js/Layouts/`
	  `resources/js/Pages/`
	  `resources/js/app.js`
	  `resources/js/bootstrap.js`
- `app/Http/Controlles/Auth`
- `routes/auth.php`

Inertia загружается в `resources/views/app.blade.php`: `<body>@inertia</body>`
Туда вкладывается приложение из `resources/js`.

`resources/js/Pages/` - дефолтные страницы аутентификации
`app/Http/Controlles/Auth` - дефолтные контроллеры аутентификации

Создать:
`resources/js/Pages/Admin/Post/Index.vue` (в IDE: `New → Vue component → Options API`)
`php artisan make:controller Admin/PostController`

## 1. Роут

Роут обычный, без API: страница отдается через web-роутинг, а Inertia сама решает, отрисовать её целиком или подменить только компонент.

IN `routes/web.php`:
```php
Route::get('/admin/posts', [PostController::class, 'index'])->name('admin.posts.index');
require __DIR__.'/auth.php';
```

Имя роута `admin.posts.index` пригодится во Vue-компонентах, чтобы не хардкодить URL.

## 2. Контроллер

Вместо `view()` возвращаем `inertia()`: первый аргумент — путь к компоненту относительно `resources/js/Pages/`, второй — массив props, которые попадут в компонент.

IN `app/Http/Controllers/Admin/PostController.php`:
```php
    public function index()
    {
        $posts = PostResource::collection(Post::all())->resolve();
        return inertia('Admin/Post/Index', compact('posts'));
    }
```

## 3. Vue-компонент

Заготовка от IDE (`Options API`): в `props` объявляем `posts` — именно так называется ключ, переданный из `compact('posts')`.
`v-for` перебирает props `posts`, `{{ post.title }}` выводит поле из `PostResource`.

IN `resources/js/Pages/Admin/Post/Index.vue`:
```vue
<template>
    <div>
        <div>
            <h3>Posts</h3>
        </div>
        <div>
            <div v-for="post in posts" class="">
                {{ post.title }}
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: "Index",
    props: {
        posts: {
            type: Array,
            required: false
        }
    }
}
</script>

<style scoped>

</style>
```

У `<template>` должен быть один корневой элемент, поэтому всё завёрнуто во внешний `<div>`.