<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PostController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Админка целиком закрыта middleware auth: код внутри опирается на текущего пользователя
// (author_id берётся из auth()->user()->profile), поэтому гость сюда попасть не должен.
// Незалогиненного Authenticate редиректит на route('login') — страницу поставил Breeze.
Route::middleware('auth')->group(function () {
    // Обычный web-маршрут, не API: страница отдаётся через web-роутинг, а Inertia сама
    // решит — отрисовать её целиком или подменить только Vue-компонент.
    // Имя admin.posts.index нужно во Vue, чтобы не хардкодить URL: route('admin.posts.index').
    Route::get('/admin/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard.index');

    Route::get('/admin/posts', [PostController::class, 'index'])->name('admin.posts.index');
    // Имена экшенов и роутов — по ресурсной конвенции Laravel: страница с формой это create,
    // сохранение — store. Объявляем /create до возможного /{post}, чтобы слово не попало в параметр.
    Route::get('/admin/posts/create', [PostController::class, 'create'])->name('admin.posts.create');
    // POST-роут в web.php проходит CSRF-проверку: axios сам подставит заголовок X-XSRF-TOKEN
    // из куки XSRF-TOKEN, потому что запрос уходит на тот же домен.
    Route::post('/admin/posts', [PostController::class, 'store'])->name('admin.posts.store');
    // whereNumber ограничивает сегмент регуляркой [0-9]+, поэтому /admin/posts/create
    // не может попасть в параметр {post} — маршрут перестаёт зависеть от порядка объявления.
    Route::get('/admin/posts/{post}', [PostController::class, 'show'])
        ->whereNumber('post')
        ->name('admin.posts.show');
    // Вторая пара ресурсной конвенции: edit отдаёт страницу с формой (GET),
    // update сохраняет изменения — как create + store, но для существующей записи.
    Route::get('/admin/posts/{post}/edit', [PostController::class, 'edit'])
        ->whereNumber('post')
        ->name('admin.posts.edit');
    // PATCH, а не PUT: форма присылает часть колонок (title, content, category_id),
    // а author_id, status и published_at не трогает. PUT по семантике заменял бы
    // ресурс целиком. Laravel их не различает — разницу задают правила и сервис.
    Route::patch('/admin/posts/{post}', [PostController::class, 'update'])
        ->whereNumber('post')
        ->name('admin.posts.update');
    // DELETE, а не POST: метод запроса и есть описание действия — отдельного слова
    // в URL не нужно. Подмена метода через _method здесь не требуется: запрос уходит
    // не из HTML-формы, а из axios, а он умеет любой глагол.
    //
    // destroy закрывает ресурсную конвенцию: index, create, store, show, edit, update,
    // destroy — те же семь маршрутов, что сгенерировал бы Route::resource().
    Route::delete('/admin/posts/{post}', [PostController::class, 'destroy'])
        ->whereNumber('post')
        ->name('admin.posts.destroy');
});

require __DIR__.'/auth.php';
