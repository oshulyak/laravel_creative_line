<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        // Таблица называется app_notifications, а не notifications: это имя занято
        // встроенной системой уведомлений Laravel (трейт Notifiable у User работает
        // именно с ней). Модель Notification связана с таблицей через $table.
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            // Получатель — профиль. Обычный внешний ключ, а не полиморфная связь:
            // уведомления в проекте получает только профиль, выбирать тип не из чего.
            $table->foreignId('profile_id')->index()->constrained('profiles');
            // Инициатор: кто прокомментировал, репостнул, лайкнул. Тоже профиль.
            //
            // Колонка нужна не для красоты: без неё нельзя ответить на вопрос
            // «мы уже уведомляли ЭТОГО человека о ТАКОМ действии ЭТОГО профиля?»,
            // а он встаёт сразу же на лайках — их снимают и ставят заново.
            $table->foreignId('actor_id')->index()->constrained('profiles');
            // Готовый текст уведомления. Собираем его в момент создания, а не при
            // показе: «Новый комментарий к публикации "Заголовок"» должен остаться
            // прежним, даже если пост потом переименуют.
            $table->text('body');
            // Источник уведомления: пост (репост, лайк) или комментарий. Та же пара
            // колонок, что у commentable и likeable, — _type и _id плюс общий индекс.
            $table->morphs('notificationable');
            // Момент прочтения. NULL — не прочитано; именно по этой колонке считается
            // число на колокольчике.
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('app_notifications');
    }
};
