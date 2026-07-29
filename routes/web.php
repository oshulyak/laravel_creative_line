<?php

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

// Обычный web-маршрут, не API: страница отдаётся через web-роутинг, а Inertia сама
// решит — отрисовать её целиком или подменить только Vue-компонент.
// Имя admin.posts.index нужно во Vue, чтобы не хардкодить URL: route('admin.posts.index').
Route::get('/admin/posts', [PostController::class, 'index'])->name('admin.posts.index');

require __DIR__.'/auth.php';
