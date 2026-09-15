<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Группа: сообщество с участниками и темами.
 *
 * Смотреть группу и её темы может любой вошедший пользователь,
 * создавать темы и писать в них — только участники (hasSubscriber()).
 *
 * В коде вступление называется подпиской, как в курсе и у профилей
 * (subscribers, is_subscribed), в интерфейсе — «Вступить» и «Выйти».
 */
class Group extends Model {
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
    ];

    /**
     * Участники группы (многие ко многим через group_profile).
     *
     * Аргументов нет, хотя метод называется subscribers, а не profiles:
     * belongsToMany берёт имя таблицы и колонок из классов моделей
     * (Group + Profile → group_profile, group_id, profile_id), имя метода
     * на них не влияет. Сравните с Profile::subscribers(): там таблица
     * названа не по конвенции, и все имена указаны руками.
     *
     * withTimestamps() — чтобы attach() и toggle() заполняли даты в pivot.
     */
    public function subscribers(): BelongsToMany {
        return $this->belongsToMany(Profile::class)->withTimestamps();
    }

    /**
     * Темы группы (внешний ключ themes.group_id).
     */
    public function themes(): HasMany {
        return $this->hasMany(Theme::class);
    }

    /**
     * Состоит ли профиль в группе.
     *
     * Правило «писать могут только участники» записано здесь один раз,
     * его вызывают Theme\StoreRequest и ThemeMessage\StoreRequest.
     *
     * subscribers() со скобками и exists(): в базу уходит один SELECT EXISTS,
     * участники в память не загружаются. У Chat::hasParticipant() наоборот:
     * участников чата единицы, и странице чата они всё равно нужны.
     * У группы участников могут быть тысячи, а для проверки нужен один ответ.
     *
     * null — пользователь без профиля: участником он быть не может.
     */
    public function hasSubscriber(?Profile $profile): bool {
        return $profile !== null
            && $this->subscribers()->whereKey($profile->id)->exists();
    }
}
