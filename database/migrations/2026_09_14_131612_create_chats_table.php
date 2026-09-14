<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Чаты: сама переписка, к которой присоединены профили-участники.
     *
     * Пары «отправитель → получатель» здесь нет: кто участвует в чате, хранит
     * chat_profile, а сообщения ссылаются на чат, а не на адресата.
     */
    public function up(): void {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            // Название чата. nullable: у диалога двух людей своего названия нет,
            // на странице вместо него показываем собеседника (ChatResource).
            $table->string('title')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('chats');
    }
};
