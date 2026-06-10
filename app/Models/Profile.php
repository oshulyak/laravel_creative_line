<?php

namespace App\Models;

use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Публикации, которые лайкнул профиль (многие ко многим через post_profile_likes).
     */
    public function likedPosts(): BelongsToMany {
        return $this->belongsToMany(Post::class, 'post_profile_likes', 'profile_id', 'post_id')->withTimestamps();
    }
}
