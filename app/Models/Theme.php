<?php

namespace App\Models;

use Database\Factories\ThemeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тема группы: заголовок и лента сообщений.
 */
class Theme extends Model {
    /** @use HasFactory<ThemeFactory> */
    use HasFactory;

    /**
     * group_id в списке нет: темы создаются через связь
     * $group->themes()->create([...]), и ключ группы она подставит сама.
     *
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'title',
    ];

    /**
     * Группа, которой принадлежит тема.
     */
    public function group(): BelongsTo {
        return $this->belongsTo(Group::class);
    }

    /**
     * Автор темы (внешний ключ themes.author_id).
     */
    public function author(): BelongsTo {
        return $this->belongsTo(Profile::class, 'author_id');
    }

    /**
     * Сообщения темы (внешний ключ theme_messages.theme_id).
     *
     * Метод называется messages, а не themeMessages: внутри темы и так
     * понятно, чьи это сообщения. Колонку theme_id Laravel выведет
     * из имени модели Theme.
     */
    public function messages(): HasMany {
        return $this->hasMany(ThemeMessage::class);
    }
}
