<?php

namespace App\Models;

use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model {
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    public const STATUS_PUBLISHED = 1;

    public const STATUS_MODERATE = 2;

    /**
     * Поля, разрешённые для массового присвоения (Post::create / $post->update).
     *
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'category_id',
        'title',
        'content',
        'img_path',
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
     * Автор публикации — профиль (внешний ключ posts.author_id).
     */
    public function author(): BelongsTo {
        return $this->belongsTo(Profile::class, 'author_id');
    }

    /**
     * Категория публикации.
     */
    public function category(): BelongsTo {
        return $this->belongsTo(Category::class);
    }

    /**
     * Комментарии к публикации.
     */
    public function comments(): HasMany {
        return $this->hasMany(Comment::class);
    }

    /**
     * Изображения публикации (внешний ключ images.post_id).
     */
    public function images(): HasMany {
        return $this->hasMany(Image::class);
    }

    /**
     * Теги публикации (многие ко многим через post_tag).
     */
    public function tags(): BelongsToMany {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    /**
     * Профили, лайкнувшие публикацию (многие ко многим через post_profile_likes).
     */
    public function likedByProfiles(): BelongsToMany {
        return $this->belongsToMany(Profile::class, 'post_profile_likes', 'post_id', 'profile_id')->withTimestamps();
    }
}
