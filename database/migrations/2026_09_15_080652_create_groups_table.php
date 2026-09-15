<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Группы: сообщества с участниками (group_profile) и темами (themes).
     *
     * Колонки владельца нет: создатель просто становится первым участником
     * (GroupService::store()).
     */
    public function up(): void {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            // Название не уникально: две группы «Laravel» различает id.
            $table->string('title');
            // Описание необязательно: группе хватает названия.
            // text, а не string: описание может быть длиннее 255 символов.
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('groups');
    }
};
