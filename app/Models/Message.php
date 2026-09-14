<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сообщение чата.
 *
 * Фабрики пока нет: в этом уроке сообщения не создаются. Она появится
 * вместе с отправкой сообщений.
 */
class Message extends Model {
    /**
     * chat_id в списке нет: сообщения будут создаваться через связь
     * $chat->messages()->create([...]), и ключ чата она подставит сама.
     *
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'content',
    ];

    /**
     * Чат, которому принадлежит сообщение.
     */
    public function chat(): BelongsTo {
        return $this->belongsTo(Chat::class);
    }

    /**
     * Автор сообщения (внешний ключ messages.author_id).
     */
    public function author(): BelongsTo {
        return $this->belongsTo(Profile::class, 'author_id');
    }
}
