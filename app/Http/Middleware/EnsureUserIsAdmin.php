<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin {
    /**
     * Пропускает запрос дальше только для администратора.
     *
     * Ставится после middleware аутентификации: auth для web-админки,
     * auth:api для API. Оба запоминают guard, через который вошёл пользователь,
     * и $request->user() без аргумента найдёт его и по сессии, и по JWT-токену.
     *
     * is_admin — аксессор User::isAdmin(): есть ли роль admin среди ролей
     * пользователя. Это один запрос к roles на запрос в админку.
     *
     * abort(403), а не response()->json(): Laravel сам ответит страницей ошибки
     * браузеру и JSON-ом тому, кто просит JSON (axios, API).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response {
        abort_unless($request->user()?->is_admin, 403);

        return $next($request);
    }
}
