<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin {
    /**
     * Пропускает запрос дальше только для администратора.
     *
     * Middleware стоит в цепочке после jwt.auth, поэтому токен уже проверен.
     * Пользователя берём явно из guard 'api' (JWT), а не полагаемся на guard
     * по умолчанию (сейчас это 'api' из .env AUTH_GUARD, см. config/auth.php) —
     * если он когда-нибудь снова станет 'web' (например, появится сессионная
     * админка), $request->user() без аргумента начнёт возвращать null здесь.
     *
     * NB: алиас 'jwt.auth' указывает на middleware из tymon/jwt-auth, помеченный
     * @deprecated. В будущем группы маршрутов стоит перевести на штатный Laravel
     * 'auth:api' (заменить 'jwt.auth' на 'auth:api' в routes/api.php): он
     * аутентифицирует через тот же JWTGuard, что и user('api'), поэтому
     * пользователь резолвится один раз из единого источника, без повторного
     * парсинга токена.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response {
        // if (!auth()->user()->is_admin){
        if (! $request->user('api')?->is_admin) {
            return response()->json(['message' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
