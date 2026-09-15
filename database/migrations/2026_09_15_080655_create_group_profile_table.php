<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Участники групп: многие ко многим между groups и profiles.
     *
     * Имя по конвенции Laravel — обе модели в единственном числе, по алфавиту:
     * Group + Profile → group_profile. Поэтому связи Group::subscribers()
     * и Profile::groups() обходятся без аргументов, как у chat_profile.
     */
    public function up(): void {
        Schema::create('group_profile', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->index()->constrained('groups');
            $table->foreignId('profile_id')->index()->constrained('profiles');
            $table->timestamps();
            // Профиль состоит в группе один раз. toggle() дубль не создаст,
            // но два одновременных клика из двух вкладок без индекса могли бы
            // записать две строки.
            $table->unique(['group_id', 'profile_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('group_profile');
    }
};
