<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Темы групп. Сама переписка лежит в theme_messages.
     */
    public function up(): void {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            // Группа, которой принадлежит тема.
            $table->foreignId('group_id')->index()->constrained('groups');
            // Автор — профиль. Имя author_id, как у posts, comments и messages.
            // Таблица указана явно: из author_id Laravel вывел бы authors.
            $table->foreignId('author_id')->index()->constrained('profiles');
            $table->string('title');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('themes');
    }
};
