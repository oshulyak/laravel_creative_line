<?php

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model {
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_MODERATE = 'moderate';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'post_id',
        'author_id',
        'parent_id',
        'content',
        'status',
        'published_at',
    ];

    public static function getStatuses(): array {
        return [
            self::STATUS_PUBLISHED => 'Опубликовано',
            self::STATUS_MODERATE => 'На модерации',
        ];
    }

    /**
     * Публикация, к которой относится комментарий.
     */
    public function post(): BelongsTo {
        return $this->belongsTo(Post::class);
    }

    /**
     * Автор комментария — профиль (внешний ключ comments.author_id).
     */
    public function author(): BelongsTo {
        return $this->belongsTo(Profile::class, 'author_id');
    }

    /**
     * Родительский комментарий (самосвязь для дерева ответов).
     */
    public function parent(): BelongsTo {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    /**
     * Дочерние комментарии — ответы (самосвязь).
     */
    public function replies(): HasMany {
        return $this->hasMany(Comment::class, 'parent_id');
    }
}
