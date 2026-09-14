<?php

namespace App\Models;

use App\Models\Traits\HasLog;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Profile extends Model {
    /** @use HasFactory<ProfileFactory> */
    use HasFactory, HasLog;

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
     *
     * using(Like::class) — как и на обратной стороне связи: pivot-строка получает
     * модель, а модель — события. Здесь лайки обычно только читают, но связь
     * должна вести себя одинаково с какой стороны к ней ни подойти.
     */
    public function likedPosts(): MorphToMany {
        return $this->morphedByMany(Post::class, 'likeable')
            ->using(Like::class)
            ->withTimestamps();
    }

    /**
     * Комментарии, которые лайкнул профиль (Likeable: многие ко многим через likeables).
     */
    public function likedComments(): MorphToMany {
        return $this->morphedByMany(Comment::class, 'likeable')
            ->using(Like::class)
            ->withTimestamps();
    }

    /**
     * Изображения, которые лайкнул профиль (Likeable: многие ко многим через likeables).
     */
    public function likedImages(): MorphToMany {
        return $this->morphedByMany(Image::class, 'likeable')
            ->using(Like::class)
            ->withTimestamps();
    }

    /**
     * Профили, подписанные на этот профиль («мои подписчики»).
     *
     * Самоссылающаяся связь многие-ко-многим: обе стороны — profiles. Угадать
     * Laravel тут не может ничего, поэтому все четыре аргумента указаны явно:
     *
     * 1. related — какую модель достаём (Profile);
     * 2. table — промежуточная таблица;
     * 3. foreignPivotKey — колонка, в которой лежит id ТЕКУЩЕГО профиля;
     * 4. relatedPivotKey — колонка, в которой лежит id того, кого достаём.
     *
     * Здесь текущий профиль — тот, НА КОГО подписаны, значит его id лежит
     * в subscribing_id, а достаём мы подписчиков из subscriber_id.
     */
    public function subscribers(): BelongsToMany {
        return $this->belongsToMany(
            Profile::class,
            'profile_subscriptions',
            'subscribing_id',
            'subscriber_id',
        )->withTimestamps();
    }

    /**
     * Профили, на которые подписан этот профиль («мои подписки»).
     *
     * Та же таблица, те же две колонки — поменялись местами. Пара
     * subscribers()/subscriptions() — две стороны одной связи.
     *
     * withTimestamps() нужен, чтобы attach()/toggle() заполняли created_at
     * и updated_at: по умолчанию Eloquent промежуточные даты не трогает.
     * Точно так же настроены likedPosts() и likedComments().
     */
    public function subscriptions(): BelongsToMany {
        return $this->belongsToMany(
            Profile::class,
            'profile_subscriptions',
            'subscriber_id',
            'subscribing_id',
        )->withTimestamps();
    }

    /**
     * Чаты, в которых участвует профиль (многие ко многим через chat_profile).
     *
     * Обратная сторона Chat::profiles(): та же таблица, те же колонки
     * и снова без аргументов.
     *
     * Модели над pivot-строкой (using(...), как у лайков) нет: событий
     * на «профиль добавлен в чат» не нужно.
     */
    public function chats(): BelongsToMany {
        return $this->belongsToMany(Chat::class)->withTimestamps();
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

    /**
     * Уведомления, адресованные этому профилю (внешний ключ app_notifications.profile_id).
     *
     * Обычный hasMany: имя колонки Laravel выведет из имени модели Profile,
     * указывать его не нужно — в отличие от posts() и comments(), где ключ
     * называется author_id.
     */
    public function notifications(): HasMany {
        return $this->hasMany(Notification::class);
    }

    /**
     * Количество НЕпрочитанных уведомлений профиля.
     *
     * Аксессор в новом стиле — так же, как is_admin у User: метод называется
     * notificationsCount(), а обращаться к нему нужно как к атрибуту
     * $profile->notifications_count (имя приводится к snake_case).
     *
     * notifications() со скобками, а не notifications: со скобками это запрос,
     * и в базу уходит SELECT COUNT(*) — одно число. Без скобок Eloquent вытащил бы
     * ВСЕ строки уведомлений в память, а заодно поднял бы на каждой из них событие
     * retrieved — и NotificationObserver пометил бы их прочитанными. Счётчик
     * обнулял бы сам себя при открытии любой страницы.
     *
     * $value — значение, которое уже лежит в модели под этим именем: его кладёт
     * туда withCount('notifications as notifications_count'). Аксессор вызывается
     * раньше всего остального и перекрывает загруженное значение, поэтому без этой
     * проверки withCount не давал бы никакой экономии. Приведение к int нужно,
     * потому что COUNT(*) в PostgreSQL приезжает строкой.
     */
    protected function notificationsCount(): Attribute {
        return Attribute::make(
            get: fn (mixed $value): int => $value !== null
                ? (int) $value
                : $this->notifications()->whereNull('read_at')->count(),
        );
    }
}
