<?php

namespace App\Models;

use Database\Factories\ChatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Чат: переписка, к которой присоединены профили-участники.
 *
 * Чаты бывают двух видов, и различаются они по title:
 * - диалог — два участника, title = NULL (заголовок из ника собеседника
 *   собирает ChatResource);
 * - групповой — title обязателен, его проверяет Client\Chat\StoreRequest.
 *
 * Поиск диалога в ChatService::storeDialog() опирается на это правило.
 *
 * HasLog не подключён, как и у Notification: логировать каждый чат незачем.
 */
class Chat extends Model {
    /** @use HasFactory<ChatFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
    ];

    /**
     * Участники чата (многие ко многим через chat_profile).
     *
     * Аргументов нет: имя таблицы chat_profile и колонки chat_id / profile_id
     * Laravel выводит из имён моделей. Сравните с Profile::subscribers(),
     * где конвенция не подошла и все имена пришлось перечислить.
     *
     * withTimestamps() — чтобы attach() заполнял created_at и updated_at:
     * по умолчанию Eloquent даты в промежуточной таблице не трогает.
     */
    public function profiles(): BelongsToMany {
        return $this->belongsToMany(Profile::class)->withTimestamps();
    }

    /**
     * Сообщения чата (внешний ключ messages.chat_id).
     */
    public function messages(): HasMany {
        return $this->hasMany(Message::class);
    }

    /**
     * Участвует ли профиль в чате.
     *
     * Правило «чат доступен только участникам» записано здесь один раз,
     * а вызывают его ChatController::show() и Message\StoreRequest::authorize().
     * Когда в курсе появятся политики, ChatPolicy будет вызывать этот же метод.
     *
     * $this->profiles — без скобок: при первом обращении Eloquent загрузит
     * участников и запомнит их на модели. В show() та же коллекция потом
     * уйдёт в ChatResource без второго запроса.
     *
     * null — пользователь без профиля: участником чата он быть не может.
     */
    public function hasParticipant(?Profile $profile): bool {
        return $profile !== null && $this->profiles->contains($profile);
    }
}
