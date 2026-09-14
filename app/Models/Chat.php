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
}
