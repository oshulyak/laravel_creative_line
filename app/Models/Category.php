<?php

namespace App\Models;

use App\Models\Traits\HasLog;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Category extends Model {
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasLog;

    /*
     * Альтернатива для учебного разбора: если бы в HasLog метод назывался booted(),
     * а модели тоже нужен был свой booted(), метод трейта можно было бы подключить
     * через alias и вызвать вручную:
     *
     * use HasFactory, HasLog {
     *     HasLog::booted as bootedHasLog;
     * }
     *
     * protected static function booted(): void {
     *     static::bootedHasLog();
     *
     *     static::created(function (Category $category): void {
     *         // Собственные обработчики событий модели.
     *     });
     * }
     *
     * В Laravel-way для таких трейтов лучше использовать bootHasLog(),
     * потому что Laravel вызывает его автоматически.
     */

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
    ];

    protected static function booted(): void {
        static::created(function (Category $category): void {
            if (app()->runningInConsole()) {
                echo 'Category::booted обработал событие created для категории #'.$category->id.PHP_EOL;
            }
        });
    }

    /**
     * Публикации этой категории.
     */
    public function posts(): HasMany {
        return $this->hasMany(Post::class);
    }

    /**
     * Обложка категории (Imageable: одно к одному).
     */
    public function image(): MorphOne {
        return $this->morphOne(Image::class, 'imageable');
    }

    /**
     * Комментарии ко всем постам этой категории (Category → Post → Comment).
     *
     * «Дальний» ключ после перехода на полиморфный commentable — это
     * comments.commentable_id, валидный лишь при commentable_type = Post,
     * поэтому добавлено условие по типу (см. подробности в Profile::postComments()).
     */
    public function comments(): HasManyThrough {
        return $this->hasManyThrough(Comment::class, Post::class, 'category_id', 'commentable_id')
            ->where('comments.commentable_type', Post::class);
    }
}
