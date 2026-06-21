<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            // Полиморфный «родитель» комментария: пост (комментарий к посту)
            // или другой комментарий (ответ в ветке). Заменяет сразу две прежние
            // колонки — post_id и parent_id — одной связью commentable.
            $table->morphs('commentable');
            $table->foreignId('author_id')->index()->constrained('profiles');
            $table->text('content');
            $table->string('status');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('comments');
    }
};
