<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Сообщения в темах групп.
     *
     * Набор колонок повторяет messages из чатов, только вместо chat_id — theme_id.
     */
    public function up(): void {
        Schema::create('theme_messages', function (Blueprint $table) {
            $table->id();
            // Тема, которой принадлежит сообщение.
            $table->foreignId('theme_id')->index()->constrained('themes');
            $table->foreignId('author_id')->index()->constrained('profiles');
            $table->text('content');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('theme_messages');
    }
};
