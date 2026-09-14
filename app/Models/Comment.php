<?php

namespace App\Models;

use App\Models\Traits\HasLog;
use App\Observers\CommentObserver;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[ObservedBy(CommentObserver::class)]
class Comment extends Model {
    /** @use HasFactory<CommentFactory> */
    use HasFactory, HasLog;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_MODERATE = 'moderate';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'content',
        'status',
        'published_at',
    ];

    /**
     * published_at — не строка, а момент времени. Каст превращает его в Carbon
     * при чтении и обратно при записи, а в JSON он уходит в ISO-8601
     * (2026-09-08T10:00:00.000000Z) — единственный формат, который одинаково
     * разбирают все браузеры.
     *
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'published_at' => 'datetime',
        ];
    }

    public static function getStatuses(): array {
        return [
            self::STATUS_PUBLISHED => 'Опубликовано',
            self::STATUS_MODERATE => 'На модерации',
        ];
    }

    /**
     * Автор комментария — профиль (внешний ключ comments.author_id).
     */
    public function author(): BelongsTo {
        return $this->belongsTo(Profile::class, 'author_id');
    }

    /**
     * Полиморфный родитель: пост (комментарий к посту) либо другой комментарий
     * (ответ в ветке). Заменяет прежние post() и parent().
     */
    public function commentable(): MorphTo {
        return $this->morphTo();
    }

    /**
     * Ответы на комментарий — дочерние комментарии, у которых commentable = этот
     * комментарий (Commentable: одно ко многим). Заменяет прежний replies().
     */
    public function comments(): MorphMany {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Изображения комментария (Imageable: одно ко многим).
     */
    public function images(): MorphMany {
        return $this->morphMany(Image::class, 'imageable');
    }

    /**
     * Файлы комментария (Fileable: одно ко многим).
     */
    public function files(): MorphMany {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * Теги комментария (Taggable: многие ко многим через taggables).
     */
    public function tags(): MorphToMany {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    /**
     * Профили, лайкнувшие комментарий (Likeable: многие ко многим через likeables).
     *
     * using(Like::class) даёт pivot-строке модель, а модели — события: без него
     * лайк комментария проходил бы мимо LikeObserver. См. Post::likedByProfiles().
     */
    public function likedByProfiles(): MorphToMany {
        return $this->morphToMany(Profile::class, 'likeable')
            ->using(Like::class)
            ->withTimestamps();
    }

    /**
     * Уведомления, порождённые этим комментарием: сам комментарий и его лайки
     * (Notificationable: одно ко многим).
     */
    public function notifications(): MorphMany {
        return $this->morphMany(Notification::class, 'notificationable');
    }
}
