<?php

use App\Http\Controllers\Client\FeedController;
use App\Http\Controllers\Client\PostController;
use App\Http\Controllers\Client\ProfileController;
use Illuminate\Support\Facades\Route;

// Файл подключается в bootstrap/app.php через then: и уже обёрнут в группу web.
//
// Весь файл под auth: гостевого режима у ленты пока нет. Лайк ставит профиль текущего
// пользователя, «мои публикации» без пользователя не существуют, и даже лента опирается
// на профиль — ей нужно знать, какие посты уже лайкнуты.
Route::middleware('auth')->group(function () {
    Route::get('feed', [FeedController::class, 'index'])->name('client.feed.index');

    // Страница «мои публикации». Слово personal — не id профиля, а фиксированный сегмент:
    // чей профиль показывать, сервер знает из сессии, а не из URL. Чужие профили
    // приедут отдельным маршрутом profiles/{profile} позже.
    Route::get('profiles/personal', [ProfileController::class, 'personal'])
        ->name('client.profiles.personal');

    // whereNumber — та же защита, что в админке: сегмент ограничен регуляркой [0-9]+,
    // и маршрут перестаёт зависеть от порядка объявления.
    Route::get('posts/{post}', [PostController::class, 'show'])
        ->whereNumber('post')
        ->name('client.posts.show');

    // Переключение лайка. POST, а не GET: действие меняет состояние на сервере,
    // а GET обязан быть безопасным (его повторяет браузер, префетчит, кладёт в историю).
    //
    // URL читается как «лайки этого поста», глагол toggle живёт в имени маршрута,
    // а не в адресе: posts/5/likes/toggle было бы RPC-стилем, а не REST.
    //
    // Почему не пара store/destroy, как у ресурса: клиент не знает наверняка, стоит ли
    // сейчас лайк (соседняя вкладка могла его снять), и выбирать между POST и DELETE ему
    // пришлось бы по устаревшим данным. Один маршрут «переключи» снимает вопрос —
    // решение принимает сервер.
    Route::post('posts/{post}/likes', [PostController::class, 'toggleLike'])
        ->whereNumber('post')
        ->name('client.posts.likes.toggle');
});
