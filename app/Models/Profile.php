<?php

namespace App\Models;

use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Profile extends Model {
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'nickname',
        'first_name',
        'second_name',
        'img_path',
        'birth_date',
        'gender',
        'city',
    ];

    /**
     * Пользователь, которому принадлежит профиль (обратная сторона hasOne).
     */
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    /**
     * Публикации, написанные этим профилем (внешний ключ posts.author_id).
     */
    public function posts(): HasMany {
        return $this->hasMany(Post::class, 'author_id');
    }

    /**
     * Комментарии, оставленные этим профилем (внешний ключ comments.author_id).
     */
    public function comments(): HasMany {
        return $this->hasMany(Comment::class, 'author_id');
    }

    /**
     * Аватар профиля (Imageable: одно к одному).
     */
    public function image(): MorphOne {
        return $this->morphOne(Image::class, 'imageable');
    }

    /**
     * Публикации, которые лайкнул профиль (Likeable: многие ко многим через likeables).
     */
    public function likedPosts(): MorphToMany {
        return $this->morphedByMany(Post::class, 'likeable')->withTimestamps();
    }

    /**
     * Комментарии, которые лайкнул профиль (Likeable: многие ко многим через likeables).
     */
    public function likedComments(): MorphToMany {
        return $this->morphedByMany(Comment::class, 'likeable')->withTimestamps();
    }

    /**
     * Изображения, которые лайкнул профиль (Likeable: многие ко многим через likeables).
     */
    public function likedImages(): MorphToMany {
        return $this->morphedByMany(Image::class, 'likeable')->withTimestamps();
    }

    /**
     * Комментарии к постам этого профиля (Profile → Post → Comment).
     *
     * Отличается от comments() выше: тот метод — это комментарии, которые
     * профиль НАПИСАЛ, а здесь — комментарии, оставленные ДРУГИМИ к его постам.
     *
     * После перехода на полиморфный commentable «дальний» ключ — это
     * comments.commentable_id, но он указывает на пост только когда
     * commentable_type = Post. Поэтому к hasManyThrough добавлено явное
     * условие по типу — иначе в выборку могли бы попасть комментарии-ответы
     * (commentable_type = Comment) со случайно совпавшим id.
     */
    public function postComments(): HasManyThrough {
        return $this->hasManyThrough(Comment::class, Post::class, 'author_id', 'commentable_id')
            ->where('comments.commentable_type', Post::class);
    }
}
