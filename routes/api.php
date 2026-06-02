<?php

use App\Http\Controllers\Api\PostController;
use Illuminate\Support\Facades\Route;

// Все маршруты этого файла автоматически получают префикс /api и middleware-группу
// "api" (см. bootstrap/app.php → withRouting(api: ...)). Поэтому URL ресурса — /api/posts.

// Post — явные REST-маршруты в стиле конспекта (как в routes/web.php).
// В отличие от web.php здесь используем настоящие HTTP-глаголы: API дёргаем из
// Postman, а не из адресной строки браузера, поэтому анти-паттерн «всё через GET» не нужен.
//   GET    /api/posts          — список
//   GET    /api/posts/{post}   — один пост (route-model binding по id)
//   POST   /api/posts          — создание
//   PATCH  /api/posts/{post}   — частичное обновление
//   DELETE /api/posts/{post}   — удаление
Route::get('posts', [PostController::class, 'index']);
Route::get('posts/{post}', [PostController::class, 'show']);
Route::post('posts', [PostController::class, 'store']);
Route::patch('posts/{post}', [PostController::class, 'update']);
Route::delete('posts/{post}', [PostController::class, 'destroy']);

// ----------------------------------------------------------------------
// Идиоматичный Laravel-вариант — одна строка вместо пяти выше:
// Route::apiResource('posts', PostController::class);
// Отличие от Route::resource(): apiResource НЕ создаёт маршруты create/edit
// (страницы HTML-форм), оставляя ровно 5 API-действий: index, show, store, update, destroy.
// ----------------------------------------------------------------------
