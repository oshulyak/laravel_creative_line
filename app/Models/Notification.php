<?php

namespace App\Models;

use App\Observers\NotificationObserver;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Уведомление внутри приложения: кому, от кого, о чём и откуда.
 *
 * HasLog намеренно не подключён: уведомление — само по себе служебная запись,
 * и лог о её создании никому не нужен. Тем более что NotificationObserver
 * обновляет уведомления при каждом показе.
 */
#[ObservedBy(NotificationObserver::class)]
class Notification extends Model {
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    /**
     * Имя таблицы указано явно: по конвенции модель Notification искала бы
     * таблицу notifications, а это имя занято встроенными уведомлениями
     * Laravel. Единственное место в проекте, где нужен $table.
     *
     * @var string
     */
    protected $table = 'app_notifications';

    /**
     * Получателя и инициатора подставляем руками: связь notifications()
     * на источнике знает про notificationable_*, но не про то, кому
     * адресовано и кем вызвано.
     *
     * @var list<string>
     */
    protected $fillable = [
        'profile_id',
        'actor_id',
        'body',
        'read_at',
    ];

    /**
     * Было ли уведомление непрочитанным в момент выборки.
     *
     * Объявлено свойством класса, а не записано в модель как атрибут:
     * $notification->wasUnread = true у Eloquent ушло бы в setAttribute(),
     * и следующий save() попытался бы записать несуществующую колонку.
     *
     * Значение ставит NotificationObserver::retrieved() — ресурс уже не смог бы
     * определить это сам, потому что к моменту его работы read_at заполнен.
     */
    public bool $wasUnread = false;

    /**
     * read_at — момент времени, а не строка: в коде это Carbon (можно сравнивать
     * и звать diffForHumans()), в JSON — ISO-8601.
     *
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'read_at' => 'datetime',
        ];
    }

    /**
     * Получатель уведомления (внешний ключ app_notifications.profile_id).
     */
    public function profile(): BelongsTo {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Инициатор события (внешний ключ app_notifications.actor_id).
     *
     * Вторая связь к той же модели Profile. Имя колонки указано явно ради
     * читаемости пары profile()/actor(): их легко перепутать, а обе смотрят
     * в одну таблицу.
     */
    public function actor(): BelongsTo {
        return $this->belongsTo(Profile::class, 'actor_id');
    }

    /**
     * Источник: комментарий к посту, пост-репост либо лайкнутая запись.
     *
     * Обратная сторона morphMany на Post и Comment. Имя метода обязано совпадать
     * с первым аргументом morphs() в миграции — отсюда notificationable.
     */
    public function notificationable(): MorphTo {
        return $this->morphTo();
    }
}
