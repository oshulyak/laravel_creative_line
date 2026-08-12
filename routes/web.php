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
});

require __DIR__.'/auth.php';
