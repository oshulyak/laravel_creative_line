<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('taggables', function (Blueprint $table) {
            $table->id();
            // Тег (общий справочник) — внешний ключ taggables.tag_id.
            $table->foreignId('tag_id')->index()->constrained('tags');
            // Полиморфная сторона: что тегируем — пост или комментарий.
            $table->morphs('taggable');
            $table->timestamps();
            // Один и тот же тег навешивается на конкретную сущность не больше одного раза.
            $table->unique(['tag_id', 'taggable_type', 'taggable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('taggables');
    }
};
