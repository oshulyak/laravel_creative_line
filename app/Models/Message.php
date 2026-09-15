<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сообщение чата.
 */
class Message extends Model {
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

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
