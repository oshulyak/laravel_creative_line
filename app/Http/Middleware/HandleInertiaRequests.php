<?php

namespace App\Http\Middleware;

use App\Http\Resources\User\AuthUserResource;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware {
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array {
        return [
            ...parent::share($request),
            'auth' => [
                // Ресурс вместо модели: $request->user() уехал бы в каждый ответ
                // всеми колонками таблицы, кроме $hidden. Ресурс называет явно,
                // что клиенту можно, и заодно доносит профиль со счётчиком
                // непрочитанных уведомлений для колокольчика в шапке.
                //
                // Тернарник обязателен: на странице логина и на главной пользователя
                // нет, а AuthUserResource::make(null) отдал бы пустой объект вместо
                // null — и проверка v-if="$page.props.auth.user" во Welcome.vue
                // перестала бы работать.
                'user' => $request->user()
                    ? AuthUserResource::make($request->user())->resolve()
                    : null,
            ],
        ];
    }
}
