<?php

namespace App\Models;

use Database\Factories\ThemeMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сообщение в теме группы.
 *
 * По форме — близнец Message: автор, текст, даты. Отдельная модель нужна,
 * потому что сообщение принадлежит теме, а доступ к нему решает членство в группе.
 */
class ThemeMessage extends Model {
    /** @use HasFactory<ThemeMessageFactory> */
    use HasFactory;

    /**
     * theme_id в списке нет: сообщения создаются через $theme->messages()->create().
     *
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'content',
    ];

    /**
     * Тема, которой принадлежит сообщение.
     */
    public function theme(): BelongsTo {
        return $this->belongsTo(Theme::class);
    }

    /**
     * Автор сообщения (внешний ключ theme_messages.author_id).
     */
    public function author(): BelongsTo {
        return $this->belongsTo(Profile::class, 'author_id');
    }
}
