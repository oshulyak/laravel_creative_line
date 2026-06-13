<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable {
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Профиль пользователя (один к одному).
     */
    public function profile(): HasOne {
        return $this->hasOne(Profile::class);
    }

    /**
     * Роли пользователя (многие ко многим через pivot role_user).
     */
    public function roles(): BelongsToMany {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * Посты пользователя через его профиль (User → Profile → Post).
     *
     * Второй ключ указан явно: posts.author_id ссылается на profiles,
     * но назван author_id, а не profile_id, поэтому конвенция Laravel
     * его не угадывает. Первый ключ (profiles.user_id) — стандартный.
     */
    public function posts(): HasManyThrough {
        return $this->hasManyThrough(Post::class, Profile::class, 'user_id', 'author_id');
    }

    /**
     * Комментарии пользователя через его профиль (User → Profile → Comment).
     */
    public function comments(): HasManyThrough {
        return $this->hasManyThrough(Comment::class, Profile::class, 'user_id', 'author_id');
    }
}
