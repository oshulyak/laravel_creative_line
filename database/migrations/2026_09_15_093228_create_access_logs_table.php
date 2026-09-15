<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Журнал запросов: одна строка на HTTP-запрос, пишет её LoggerMiddleware.
     */
    public function up(): void {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            // Когда пришёл запрос. Отдельная колонка вместо timestamps():
            // запись журнала не редактируется, и updated_at ей не нужен.
            $table->dateTime('datetime');
            // Раздел приложения: client, admin или api (LoggerMiddleware::module()).
            $table->string('module');
            // Кто сделал запрос. nullable — у гостя пользователя нет.
            // nullOnDelete: удаление пользователя не должно упираться в его журнал —
            // записи останутся, ссылка на пользователя обнулится.
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            // Подробности: метод, шаблон пути, имена полей, ответ и статистика SQL.
            // jsonb, а не json: PostgreSQL хранит его разобранным, и по ключам
            // внутри можно искать: data->>'path', data->'sql'->>'total_sql'.
            $table->jsonb('data');
            // Главный сценарий чтения — «журнал раздела за период».
            $table->index(['datetime', 'module']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('access_logs');
    }
};
