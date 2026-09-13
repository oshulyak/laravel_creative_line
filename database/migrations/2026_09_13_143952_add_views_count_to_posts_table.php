<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::table('posts', function (Blueprint $table) {
            // Счётчик просмотров прямо в строке поста: одна колонка вместо отдельной
            // таблицы просмотров. Цена простоты — нет уникальности и нет дат,
            // поэтому статистика берёт из него только накопленный итог.
            //
            // default(0) обязателен: в таблице уже есть посты, и NOT NULL-колонку
            // без значения по умолчанию PostgreSQL к ним не добавил бы. С default
            // все существующие строки сразу получат 0.
            $table->unsignedInteger('views_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('views_count');
        });
    }
};
