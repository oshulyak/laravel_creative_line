<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Post — оригинальные RESTful-маршруты (временно закомментированы для браузерной проверки):
// Route::get('posts', [PostController::class, 'index']);
// Route::post('posts', [PostController::class, 'store']);
// Route::get('posts/{post}/show', [PostController::class, 'show']);
// Route::put('posts/{post}', [PostController::class, 'update']);
// Route::delete('posts/{post}', [PostController::class, 'destroy']);

// Post — ВРЕМЕННО все операции через GET, чтобы вызывать прямо из адресной строки браузера.
// Это анти-паттерн (GET меняет данные!) — вернуть POST/PUT/DELETE после проверки.
Route::get('posts', [PostController::class, 'index']);
Route::get('posts/store', [PostController::class, 'store']);
Route::get('posts/{post}/show', [PostController::class, 'show']);
Route::get('posts/{post}/update', [PostController::class, 'update']);
Route::get('posts/{post}/destroy', [PostController::class, 'destroy']);

// Comment
Route::get('comments', [CommentController::class, 'index']);
Route::post('comments', [CommentController::class, 'store']);
Route::get('comments/{comment}/show', [CommentController::class, 'show']);
Route::put('comments/{comment}', [CommentController::class, 'update']);
Route::delete('comments/{comment}', [CommentController::class, 'destroy']);

// Category
Route::get('categories', [CategoryController::class, 'index']);
Route::post('categories', [CategoryController::class, 'store']);
Route::get('categories/{category}/show', [CategoryController::class, 'show']);
Route::put('categories/{category}', [CategoryController::class, 'update']);
Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

// Tag
Route::get('tags', [TagController::class, 'index']);
Route::post('tags', [TagController::class, 'store']);
Route::get('tags/{tag}/show', [TagController::class, 'show']);
Route::put('tags/{tag}', [TagController::class, 'update']);
Route::delete('tags/{tag}', [TagController::class, 'destroy']);

// Role
Route::get('roles', [RoleController::class, 'index']);
Route::post('roles', [RoleController::class, 'store']);
Route::get('roles/{role}/show', [RoleController::class, 'show']);
Route::put('roles/{role}', [RoleController::class, 'update']);
Route::delete('roles/{role}', [RoleController::class, 'destroy']);

// User
Route::get('users', [UserController::class, 'index']);
Route::post('users', [UserController::class, 'store']);
Route::get('users/{user}/show', [UserController::class, 'show']);
Route::put('users/{user}', [UserController::class, 'update']);
Route::delete('users/{user}', [UserController::class, 'destroy']);

// Profile
Route::get('profiles', [ProfileController::class, 'index']);
Route::post('profiles', [ProfileController::class, 'store']);
Route::get('profiles/{profile}/show', [ProfileController::class, 'show']);
Route::put('profiles/{profile}', [ProfileController::class, 'update']);
Route::delete('profiles/{profile}', [ProfileController::class, 'destroy']);

// Image — без update (картинку перезагружают, а не правят)
Route::get('images', [ImageController::class, 'index']);
Route::post('images', [ImageController::class, 'store']);
Route::get('images/{image}/show', [ImageController::class, 'show']);
Route::delete('images/{image}', [ImageController::class, 'destroy']);

// Like — только поставить (store) и снять (destroy)
Route::post('likes', [LikeController::class, 'store']);
Route::delete('likes/{like}', [LikeController::class, 'destroy']);

// ----------------------------------------------------------------------
// Идиоматичный Laravel-вариант тех же маршрутов: Route::resource().
// Одна строка на модель заменяет пять явных выше и сама:
//   - строит ресурсные URL по конвенции (show → posts/{post}, без /show);
//   - задаёт имена маршрутов (posts.index, posts.show, posts.store, ...);
//   - создаёт 7 действий: index, create, store, show, edit, update, destroy.
// create и edit — это GET-страницы с формами; в активном блоке их нет, т.к.
// форм мы пока не делаем (для server-rendered это штатно, добавим позже).
// Урезать набор: ->only([...]) оставит указанные, ->except([...]) уберёт.
// ----------------------------------------------------------------------
// Route::resource('posts', PostController::class);
// Route::resource('comments', CommentController::class);
// Route::resource('categories', CategoryController::class);
// Route::resource('tags', TagController::class);
// Route::resource('roles', RoleController::class);
// Route::resource('users', UserController::class);
// Route::resource('profiles', ProfileController::class);
// Route::resource('images', ImageController::class)->except(['edit', 'update']);
// Route::resource('likes', LikeController::class)->only(['store', 'destroy']);
