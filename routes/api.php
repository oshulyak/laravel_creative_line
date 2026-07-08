<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\LikeController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Все маршруты этого файла автоматически получают префикс /api и middleware-группу
// "api" (см. bootstrap/app.php → withRouting(api: ...)). Поэтому URL ресурса — /api/posts.

// Идиоматичный Laravel-вариант: одна строка Route::apiResource(...) заменяет пять явных
// REST-маршрутов. apiResource регистрирует ровно 5 API-действий — index, show, store,
// update, destroy — и НЕ создаёт create/edit (HTML-формы), в отличие от Route::resource().
// Имя параметра маршрута Laravel берёт из единственного числа ресурса:
// posts → {post}, categories → {category} и т.д. — совпадает с типами в контроллерах.

Route::post('auth/login', [AuthController::class, 'login']);
Route::group(['middleware' => 'jwt.auth', 'prefix' => 'auth'], function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('me', [AuthController::class, 'me']);
});

Route::group(['middleware' => ['jwt.auth', 'admin']], function () {
    Route::apiResource('posts', PostController::class);
});

Route::apiResource('categories', CategoryController::class);
Route::apiResource('tags', TagController::class);
Route::apiResource('roles', RoleController::class);
Route::apiResource('profiles', ProfileController::class);
Route::apiResource('comments', CommentController::class);
Route::apiResource('users', UserController::class);
Route::apiResource('images', ImageController::class)->except(['update']); // без update: картинку перезагружают через store
Route::apiResource('likes', LikeController::class)->only(['store', 'destroy']); // только поставить/снять лайк

// ----------------------------------------------------------------------
// Явные REST-эквиваленты тех же маршрутов (в стиле конспекта) — оставлены
// закомментированными для наглядности: видно, какие пять строк сворачивает
// каждый apiResource выше.
// ----------------------------------------------------------------------
// Post
// Route::get('posts', [PostController::class, 'index']);
// Route::get('posts/{post}', [PostController::class, 'show']);
// Route::post('posts', [PostController::class, 'store']);
// Route::patch('posts/{post}', [PostController::class, 'update']);
// Route::delete('posts/{post}', [PostController::class, 'destroy']);

// Category
// Route::get('categories', [CategoryController::class, 'index']);
// Route::get('categories/{category}', [CategoryController::class, 'show']);
// Route::post('categories', [CategoryController::class, 'store']);
// Route::patch('categories/{category}', [CategoryController::class, 'update']);
// Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

// Tag
// Route::get('tags', [TagController::class, 'index']);
// Route::get('tags/{tag}', [TagController::class, 'show']);
// Route::post('tags', [TagController::class, 'store']);
// Route::patch('tags/{tag}', [TagController::class, 'update']);
// Route::delete('tags/{tag}', [TagController::class, 'destroy']);

// Role
// Route::get('roles', [RoleController::class, 'index']);
// Route::get('roles/{role}', [RoleController::class, 'show']);
// Route::post('roles', [RoleController::class, 'store']);
// Route::patch('roles/{role}', [RoleController::class, 'update']);
// Route::delete('roles/{role}', [RoleController::class, 'destroy']);

// Profile
// Route::get('profiles', [ProfileController::class, 'index']);
// Route::get('profiles/{profile}', [ProfileController::class, 'show']);
// Route::post('profiles', [ProfileController::class, 'store']);
// Route::patch('profiles/{profile}', [ProfileController::class, 'update']);
// Route::delete('profiles/{profile}', [ProfileController::class, 'destroy']);

// Comment
// Route::get('comments', [CommentController::class, 'index']);
// Route::get('comments/{comment}', [CommentController::class, 'show']);
// Route::post('comments', [CommentController::class, 'store']);
// Route::patch('comments/{comment}', [CommentController::class, 'update']);
// Route::delete('comments/{comment}', [CommentController::class, 'destroy']);

// User
// Route::get('users', [UserController::class, 'index']);
// Route::get('users/{user}', [UserController::class, 'show']);
// Route::post('users', [UserController::class, 'store']);
// Route::patch('users/{user}', [UserController::class, 'update']);
// Route::delete('users/{user}', [UserController::class, 'destroy']);

// Image — без update (картинку перезагружают через store, а не правят)
// Route::get('images', [ImageController::class, 'index']);
// Route::get('images/{image}', [ImageController::class, 'show']);
// Route::post('images', [ImageController::class, 'store']);
// Route::delete('images/{image}', [ImageController::class, 'destroy']);

// Like — только поставить (store) и снять (destroy)
// Route::post('likes', [LikeController::class, 'store']);
// Route::delete('likes/{like}', [LikeController::class, 'destroy']);
