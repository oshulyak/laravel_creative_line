<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class LoggerMiddlewareTest extends TestCase {
    use RefreshDatabase;

    public function test_request_is_logged_with_its_sql_queries(): void {
        $viewer = Profile::factory()->create();

        $this->actingAs($viewer->user)->get(route('client.feed.index'));

        $log = AccessLog::sole();

        $this->assertSame('client', $log->module);
        $this->assertSame($viewer->user_id, $log->user_id);
        $this->assertSame('GET', $log->data['method']);
        $this->assertSame('feed', $log->data['path']);
        $this->assertSame(200, $log->data['status_code']);

        // Счётчик пагинации ленты — запрос контроллера. Раз он в журнале,
        // слушатель видел SQL всего запроса, а не только своего кода.
        $feedCount = $log->data['sql']['log']['select count(*) as "aggregate" from "posts" where "status" = ?'];
        $this->assertSame(1, $feedCount['count']);
    }

    /**
     * Токен сброса пароля приходит прямо в адресе: reset-password/{token}.
     * Упадёт, если в path пишется $request->path(), а не шаблон маршрута.
     */
    public function test_secret_from_url_is_not_logged(): void {
        $this->get(route('password.reset', 'secret-reset-token'));

        $data = AccessLog::sole()->data;

        $this->assertSame('reset-password/{token}', $data['path']);
        $this->assertStringNotContainsString('secret-reset-token', json_encode($data));
    }

    /**
     * Та же форма отправляет токен и пароль в теле запроса. Журнал знает,
     * какие поля пришли, но не их значения.
     */
    public function test_request_values_are_not_logged(): void {
        $this->post(route('password.store'), [
            'token' => 'secret-reset-token',
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $data = AccessLog::sole()->data;

        $this->assertSame(['token', 'email', 'password', 'password_confirmation'], $data['fields']);
        $this->assertStringNotContainsString('secret-reset-token', json_encode($data));
        $this->assertStringNotContainsString('secret-password', json_encode($data));
    }

    /**
     * Гость: админка ответит редиректом на вход, API — списком. Раздел
     * от ответа не зависит, только от адреса.
     *
     * TestWith — один тест, запущенный с разными данными: у случаев
     * одинаковые подготовка и проверки.
     */
    #[TestWith(['/admin/posts', 'admin'])]
    #[TestWith(['/api/categories', 'api'])]
    public function test_module_is_detected_by_path(string $path, string $module): void {
        $this->get($path);

        $this->assertSame($module, AccessLog::sole()->module);
    }

    /**
     * Guard по умолчанию — web. Упадёт, если журнал берёт пользователя
     * через $request->user(): для запроса с JWT-токеном там null.
     *
     * fromUser() только выпускает токен и не запоминает пользователя в guard:
     * найти его журнал сможет, лишь разобрав заголовок Authorization.
     */
    public function test_api_request_is_logged_with_token_user(): void {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)->getJson('/api/categories');

        $this->assertSame($user->id, AccessLog::sole()->user_id);
    }

    public function test_failed_request_logs_exception_class(): void {
        $this->getJson('/api/categories/999999');

        $data = AccessLog::sole()->data;

        $this->assertSame(404, $data['status_code']);
        $this->assertSame(ModelNotFoundException::class, $data['exception']);
    }

    public function test_service_paths_are_not_logged(): void {
        $this->postJson('/broadcasting/auth');

        $this->assertDatabaseCount('access_logs', 0);
    }
}
