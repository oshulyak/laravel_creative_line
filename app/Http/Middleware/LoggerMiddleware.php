<?php

namespace App\Http\Middleware;

use App\Models\AccessLog;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Журнал запросов: одна строка access_logs на каждый HTTP-запрос.
 *
 * Кроме того, кто и куда пришёл, сохраняет статистику SQL за весь запрос.
 * Зарегистрирован в глобальном стеке (bootstrap/app.php), чтобы видеть
 * и запросы сессии, и вход пользователя, и привязку моделей.
 *
 * Значений из запроса журнал не хранит: ни адреса целиком, ни данных формы,
 * ни сообщений исключений. В них бывают пароли, токены и личные сообщения.
 */
class LoggerMiddleware {
    /**
     * Служебные адреса: их вызывает не пользователь, а фронтенд или Telescope.
     *
     * @var list<string>
     */
    private const EXCLUDED_PATHS = [
        // Панель Telescope опрашивает сервер каждые несколько секунд.
        'telescope*',
        // Echo авторизует каждый приватный канал: 58 запросов из 141 в Telescope.
        'broadcasting/auth',
    ];

    /**
     * Типы SQL, которые считаем по отдельности.
     *
     * @var list<string>
     */
    private const SQL_TYPES = ['select', 'insert', 'update', 'delete'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response {
        // Исключения проверяем до DB::listen(): для служебного адреса
        // и слушатель заводить незачем.
        if ($request->is(...self::EXCLUDED_PATHS)) {
            return $next($request);
        }

        $sql = [
            'select' => 0,
            'insert' => 0,
            'update' => 0,
            'delete' => 0,
            'total_sql' => 0,
            'total_time_ms' => 0.0,
            'log' => [],
        ];

        // Функция вызывается после каждого SQL-запроса приложения.
        // &$sql — по ссылке: без & замыкание меняло бы свою копию массива,
        // и после $next() здесь остались бы нули.
        DB::listen(function (QueryExecuted $query) use (&$sql): void {
            // Тип — первое слово запроса.
            $type = Str::lower(Str::before(ltrim($query->sql), ' '));

            if (in_array($type, self::SQL_TYPES, true)) {
                $sql[$type]++;
            }

            $sql['total_sql']++;
            $sql['total_time_ms'] += $query->time;

            // Ключ — SQL с плейсхолдерами ?, без значений. Запросы одной формы
            // складываются в одну строку: count > 1 — тот же «duplicated»,
            // что показывает Telescope.
            $sql['log'][$query->sql]['count'] = ($sql['log'][$query->sql]['count'] ?? 0) + 1;
            $sql['log'][$query->sql]['time_ms'] = ($sql['log'][$query->sql]['time_ms'] ?? 0) + $query->time;
        });

        $response = $next($request);

        // Всё ниже выполняется уже после контроллера: известны маршрут,
        // пользователь, статус ответа и все SQL-запросы.
        $module = $this->module($request);

        // exception есть у ответов, собранных из исключения: 403, 404, 422, 500.
        // ?? null — у файловых и потоковых ответов такого свойства нет вовсе.
        $exception = $response->exception ?? null;

        // INSERT самой этой записи в статистику не попадёт: массив $sql
        // копируется в data раньше, чем запрос выполнится.
        AccessLog::create([
            'datetime' => now(),
            'module' => $module,
            // Guard по умолчанию — web: он знает пользователя по сессии.
            // Пользователя API с JWT-токеном знает только guard api,
            // и $request->user() без аргумента вернул бы для него null.
            'user_id' => ($module === 'api' ? $request->user('api') : $request->user())?->id,
            'data' => [
                'method' => $request->method(),
                // Шаблон маршрута, а не настоящий адрес: reset-password/{token},
                // а не reset-password/9f86d08…. Токен сброса пароля приходит прямо
                // в адресе, и документация Laravel называет его секретным.
                // Заодно все страницы постов складываются в одну строку posts/{post}.
                //
                // У запроса без маршрута (404 на несуществующий адрес) шаблона нет,
                // будет null. Сырой адрес не пишем: в нём может оказаться что угодно.
                'path' => $request->route()?->uri(),
                // Только имена полей, без значений. Значения — это пароли, токены
                // и тексты личных сообщений. Список «что скрыть» всегда отстаёт
                // от новых форм, а понять, что пришло в запросе, помогают и имена.
                'fields' => array_keys($request->all()),
                'status_code' => $response->getStatusCode(),
                // Только класс. Сообщение исключения тоже несёт значения:
                // у QueryException в нём SQL с подставленными данными.
                'exception' => $exception ? $exception::class : null,
                'ip_address' => $request->ip(),
                'sql' => $sql,
            ],
        ]);

        return $response;
    }

    /**
     * Раздел приложения по адресу запроса.
     *
     * Всё, что не API и не админка, — клиентская часть: лента, профили,
     * чаты, группы, а заодно вход и регистрация от Breeze.
     */
    private function module(Request $request): string {
        return match (true) {
            $request->is('api/*') => 'api',
            $request->is('admin/*') => 'admin',
            default => 'client',
        };
    }
}
