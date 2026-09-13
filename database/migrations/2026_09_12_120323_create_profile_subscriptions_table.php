<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Подписки профилей друг на друга: многие ко многим, где обе стороны — profiles.
     *
     * Конвенция имени «две модели в единственном числе по алфавиту» дала бы
     * profile_profile, поэтому имя выбрано по смыслу.
     *
     * Отдельной модели Subscription нет: кроме пары ключей и дат в таблице ничего
     * не лежит, связи belongsToMany в Profile достаточно.
     */
    public function up(): void {
        Schema::create('profile_subscriptions', function (Blueprint $table) {
            $table->id();
            // Кто подписался.
            //
            // constrained('profiles') с явным именем таблицы: из subscriber_id
            // Laravel вывел бы таблицу subscribers, которой не существует.
            $table->foreignId('subscriber_id')->index()->constrained('profiles');
            // На кого подписался.
            $table->foreignId('subscribing_id')->index()->constrained('profiles');
            $table->timestamps();
            // Пара уникальна: подписаться на один профиль дважды нельзя.
            // toggle() и так не создаст дубль, но два одновременных клика
            // в двух вкладках без этого индекса могли бы записать две строки.
            $table->unique(['subscriber_id', 'subscribing_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('profile_subscriptions');
    }
};
