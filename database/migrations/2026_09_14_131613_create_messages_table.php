<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Сообщения чатов.
     *
     * Схема заводится сейчас, хотя сообщения в этом уроке не создаются:
     * пустая миграция означала бы позже либо add_…_to_messages_table,
     * либо migrate:fresh.
     */
    public function up(): void {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            // Чат, которому принадлежит сообщение. Не «получатель»: сообщение
            // адресовано всем участникам чата.
            $table->foreignId('chat_id')->index()->constrained('chats');
            // Автор — профиль. Имя author_id, а не profile_id: так названы авторы
            // у posts и comments. Таблица указана явно — из author_id Laravel
            // вывел бы authors.
            $table->foreignId('author_id')->index()->constrained('profiles');
            $table->text('content');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('messages');
    }
};
