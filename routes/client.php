<?php

use App\Http\Controllers\Client\CommentController;
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
    // открываются маршрутом profiles/{profile} ниже.
    Route::get('profiles/personal', [ProfileController::class, 'personal'])
        ->name('client.profiles.personal');

    // Страница чужого профиля. Порядок объявления относительно profiles/personal
    // роли не играет: whereNumber() ограничивает сегмент цифрами, и слово personal
    // под этот маршрут не подойдёт никогда.
    Route::get('profiles/{profile}', [ProfileController::class, 'show'])
        ->whereNumber('profile')
        ->name('client.profiles.show');

    // Переключение подписки. Форма ровно та же, что у лайка: POST на «подписчиков»
    // профиля, а глагол toggle живёт в имени маршрута, а не в адресе.
    //
    // Один toggle вместо пары store/destroy — по той же причине, что у лайков:
    // клиент не знает наверняка, подписан ли он прямо сейчас, решает сервер.
    Route::post('profiles/{profile}/subscribers', [ProfileController::class, 'toggleSubscribe'])
        ->whereNumber('profile')
        ->name('client.profiles.subscribers.toggle');

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

    // Репост публикации. Адрес вложен в оригинал: репост не бывает сам по себе,
    // он всегда «репост чего-то» — та же форма, что у posts/{post}/comments.
    //
    // Последний сегмент — reposts, хотя модель та же самая Post. В URL называем
    // РОЛЬ, а не класс: posts/5/posts читалось бы как опечатка. Ровно тот же приём,
    // что с replies у ответов на комментарии.
    //
    // Только store: списка репостов и их удаления в задании нет. Маршруты заводим
    // под то, что реально нужно, а не «на вырост».
    Route::post('posts/{post}/reposts', [PostController::class, 'storeRepost'])
        ->whereNumber('post')
        ->name('client.posts.reposts.store');

    // DELETE, а не POST: глагол уже описывает действие, и админский маршрут
    // admin.posts.destroy объявлен так же. Адрес совпадает с показом поста —
    // различает их метод, это и есть REST.
    Route::delete('posts/{post}', [PostController::class, 'destroy'])
        ->whereNumber('post')
        ->name('client.posts.destroy');

    // Список комментариев поста. Отдельный маршрут, а не проп страницы: комментарии
    // догружаются порциями уже после того, как пост показан, и ходить за ними будет
    // axios, а не Inertia. Заодно страница поста не меняется вовсе.
    //
    // Адрес вложенный — posts/{post}/comments: комментарий не существует сам по себе,
    // он всегда чей-то. Это стандартная форма вложенного ресурса в REST.
    Route::get('posts/{post}/comments', [CommentController::class, 'index'])
        ->whereNumber('post')
        ->name('client.posts.comments.index');

    // Тот же адрес, другой глагол: GET читает список, POST добавляет в него запись.
    Route::post('posts/{post}/comments', [CommentController::class, 'store'])
        ->whereNumber('post')
        ->name('client.posts.comments.store');

    // Лайк комментария — близнец client.posts.likes.toggle. Адрес НЕ вложен в пост:
    // у комментария есть собственный id, и знать его родителя, чтобы поставить лайк,
    // не нужно. Вложенность в URL оправдана там, где без родителя не найти ребёнка.
    Route::post('comments/{comment}/likes', [CommentController::class, 'toggleLike'])
        ->whereNumber('comment')
        ->name('client.comments.likes.toggle');

    // Ответы на комментарий. Адрес вложен в комментарий, а не в пост: ветка
    // принадлежит конкретному комментарию, и id поста для неё избыточен.
    //
    // Последний сегмент — replies, хотя модель та же самая Comment. В URL мы
    // называем РОЛЬ, а не класс: comments/5/comments читалось бы как опечатка,
    // а comments/5/replies сразу говорит, что лежит по адресу.
    Route::get('comments/{comment}/replies', [CommentController::class, 'replies'])
        ->whereNumber('comment')
        ->name('client.comments.replies.index');

    // Тот же адрес, POST — добавить ответ в ветку. Полная симметрия с парой
    // client.posts.comments.index / .store: GET читает список, POST дописывает.
    Route::post('comments/{comment}/replies', [CommentController::class, 'storeReply'])
        ->whereNumber('comment')
        ->name('client.comments.replies.store');
});
