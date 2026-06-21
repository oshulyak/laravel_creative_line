<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('likeables', function (Blueprint $table) {
            $table->id();
            // Полиморфная сторона лайка: пост, комментарий или изображение.
            $table->morphs('likeable');
            // Кто поставил лайк — профиль (внешний ключ likeables.profile_id).
            $table->foreignId('profile_id')->index()->constrained('profiles');
            $table->timestamps();
            // Один профиль лайкает конкретную сущность не больше одного раза.
            $table->unique(['profile_id', 'likeable_type', 'likeable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('likeables');
    }
};
