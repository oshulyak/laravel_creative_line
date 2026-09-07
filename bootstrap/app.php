<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // then — «после того, как фреймворк зарегистрировал свои файлы маршрутов,
        // зарегистрируй ещё вот эти». Клиентская часть — отдельный раздел приложения,
        // и здесь видно, из чего роутинг вообще состоит: web + api + client.
        //
        // middleware('web') обязателен: именно эта группа даёт сессию, куку XSRF-TOKEN
        // и middleware HandleInertiaRequests. Без неё маршруты формально работают,
        // но auth не видит пользователя, а Inertia не отдаёт страницу.
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/client.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Короткий alias 'admin' для маршрутов вместо полного имени класса.
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
