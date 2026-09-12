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
            // nullable обязателен: parent_id есть только у репостов, а обычных постов
            // в таблице большинство. Колонка без nullable не добавилась бы к непустой
            // таблице вовсе — PostgreSQL потребовал бы значение для существующих строк.
            //
            // constrained('posts') — таблицу указываем явно. Обычно Laravel выводит её
            // из имени колонки (category_id → categories), но parent_id имени таблицы
            // не содержит, и угадывать тут нечего.
            //
            // Это самоссылающийся внешний ключ: и родитель, и ребёнок лежат в одной
            // таблице. Для базы в этом нет ничего особенного.
            //
            // nullOnDelete() — главное решение миграции. Поведение по умолчанию
            // (RESTRICT) не дало бы удалить пост, у которого есть чужие репосты,
            // а cascadeOnDelete() удалил бы вместе с оригиналом чужие публикации.
            // Обнуление оставляет репост в живых: он превращается в обычный пост.
            //
            // Порядок колонок в PostgreSQL не настраивается: ->after() понимает
            // только MySQL, поэтому не пишем его вовсе.
            $table->foreignId('parent_id')
                ->nullable()
                ->index()
                ->constrained('posts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('posts', function (Blueprint $table) {
            // Сначала снять ограничение, потом убрать колонку — иначе PostgreSQL
            // не даст удалить колонку, на которой висит внешний ключ.
            // dropConstrainedForeignId() делает оба шага одним вызовом.
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
