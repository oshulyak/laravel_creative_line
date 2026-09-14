<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Участники чатов: многие ко многим между chats и profiles.
     *
     * Имя по конвенции Laravel — обе модели в единственном числе, по алфавиту,
     * через подчёркивание: Chat + Profile → chat_profile. Поэтому связи
     * Chat::profiles() и Profile::chats() обходятся без аргументов —
     * в отличие от profile_subscriptions.
     */
    public function up(): void {
        Schema::create('chat_profile', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->index()->constrained('chats');
            $table->foreignId('profile_id')->index()->constrained('profiles');
            $table->timestamps();
            // Профиль участвует в чате один раз. attach(), в отличие от toggle(),
            // дубли не проверяет: повторный вызов молча записал бы вторую строку.
            //
            // От второго чата с той же парой профилей индекс не защищает — это
            // разные chat_id. Эту проверку делает ProfileController::storeChat().
            $table->unique(['chat_id', 'profile_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('chat_profile');
    }
};
