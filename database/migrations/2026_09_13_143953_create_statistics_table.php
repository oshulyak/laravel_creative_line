<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        // Одна строка — один день. Это снимок: сколько всего было в базе
        // на момент ночного запуска команды statistics:aggregate.
        Schema::create('statistics', function (Blueprint $table) {
            $table->id();

            // unique — главная строка миграции. Правило «одна строка на дату»
            // держит не только updateOrCreate() в команде, но и сама база:
            // дубль за тот же день не вставится, даже если код однажды ошибётся.
            $table->date('date')->unique();

            $table->unsignedInteger('posts_count');
            $table->unsignedInteger('reposts_count');
            $table->unsignedInteger('comments_count');
            $table->unsignedInteger('likes_count');
            $table->unsignedInteger('views_count');

            // Отношения храним готовыми, хотя их можно посчитать из колонок выше:
            // таблица — отчёт, её читают, а не пересчитывают.
            //
            // decimal(10, 4), а не float: в базе лежит ровно 0.0523, без хвоста
            // 0.052299999. Четыре знака нужны лайкам на просмотр — это малые доли.
            //
            // nullable — когда делить не на что (ноль просмотров или комментариев).
            // NULL честно говорит «не определено», а 0 соврал бы «лайков нет».
            $table->decimal('likes_to_views_ratio', 10, 4)->nullable();
            $table->decimal('likes_to_comments_ratio', 10, 4)->nullable();

            // updated_at показывает, когда строку пересчитали в последний раз:
            // повторный запуск в тот же день обновляет её, а не создаёт новую.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('statistics');
    }
};
