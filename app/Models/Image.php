<?php

namespace App\Models;

use App\Models\Traits\HasLog;
use Database\Factories\ImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Image extends Model {
    /** @use HasFactory<ImageFactory> */
    use HasFactory, HasLog;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'img_path',
    ];

    /**
     * Владелец изображения — пост, комментарий, категория или профиль
     * (обратная сторона morphOne/morphMany).
     */
    public function imageable(): MorphTo {
        return $this->morphTo();
    }

    /**
     * Файл-оригинал изображения (Fileable: одно к одному).
     */
    public function file(): MorphOne {
        return $this->morphOne(File::class, 'fileable');
    }

    /**
     * Профили, лайкнувшие изображение (Likeable: многие ко многим через likeables).
     *
     * using(Like::class) стоит и здесь — ради единообразия всех трёх лайкаемых
     * сущностей. Уведомления лайк картинки не породит: у Image нет автора-профиля,
     * и LikeObserver такой лайк пропускает.
     */
    public function likedByProfiles(): MorphToMany {
        return $this->morphToMany(Profile::class, 'likeable')
            ->using(Like::class)
            ->withTimestamps();
    }
}
