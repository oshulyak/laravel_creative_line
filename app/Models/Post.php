<?php

namespace App\Models;

use App\Observers\PostObserver;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[ObservedBy([PostObserver::class])]
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
     * Комментарии к публикации (Commentable: одно ко многим).
     */
    public function comments(): MorphMany {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Изображения публикации (Imageable: одно ко многим).
     */
    public function images(): MorphMany {
        return $this->morphMany(Image::class, 'imageable');
    }

    /**
     * Файлы публикации (Fileable: одно ко многим).
     */
    public function files(): MorphMany {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * Теги публикации (Taggable: многие ко многим через taggables).
     */
    public function tags(): MorphToMany {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    /**
     * Профили, лайкнувшие публикацию (Likeable: многие ко многим через likeables).
     */
    public function likedByProfiles(): MorphToMany {
        return $this->morphToMany(Profile::class, 'likeable')->withTimestamps();
    }
}
