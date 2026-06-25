<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('logs', function (Blueprint $table) {
            $table->id();
            $table->string('model')->index();
            $table->string('action')->index();
            $table->jsonb('old_attributes');
            $table->jsonb('new_attributes');
            $table->jsonb('changed_attributes');
            $table->timestamps();

            $table->index(['model', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('logs');
    }
};
