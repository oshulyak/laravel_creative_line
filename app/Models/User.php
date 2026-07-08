<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Traits\HasLog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]

class User extends Authenticatable implements JWTSubject {
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasLog, Notifiable;

    // Get the identifier that will be stored in the subject claim of the JWT.
    public function getJWTIdentifier() {
        return $this->getKey();
    }

    // Return a key value array, containing any custom claims to be added to the JWT.
    public function getJWTCustomClaims() {
        return [];
    }

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
     * Является ли пользователь администратором — есть ли у него роль admin.
     *
     * Новый стиль аксессоров Laravel: метод isAdmin() автоматически доступен
     * как атрибут $user->is_admin (имя приводится к snake_case). Для одиночной
     * проверки в middleware обращение к $this->roles допустимо; при массовой
     * проверке стоит заранее eager-load'ить связь
     * ( `$users = User::with('roles')->get();` ),
     * иначе получим N+1.
     */
    protected function isAdmin(): Attribute {
        return Attribute::make(
            get: fn (): bool => $this->roles->contains('title', 'admin'),
        );
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
