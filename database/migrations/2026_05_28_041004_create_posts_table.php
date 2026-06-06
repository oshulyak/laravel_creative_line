<?php

use App\Models\Post;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->index()->constrained('profiles');
            $table->foreignId('category_id')->nullable()->index()->constrained('categories');
            $table->string('title')->unique()->index();
            $table->text('content');
            $table->string('img_path')->nullable();
            $table->unsignedTinyInteger('status')->default(Post::STATUS_PUBLISHED);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('posts');
    }
};
