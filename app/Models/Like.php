<?php

namespace App\Models;

use App\Observers\LikeObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Строка полиморфного pivot likeables: «профиль лайкнул запись».
 *
 * MorphPivot, а не Model: у полиморфной промежуточной таблицы есть тип
 * (likeable_type), и обычный Pivot о нём не знает.
 *
 * Модель заведена ради ОДНОГО — событий. Без неё attach() пишет строку
 * напрямую запросом, никакой модели не создаётся, и подписаться на лайк нечем.
 * Событие появляется только вместе с ->using(Like::class) в связях лайков.
 */
#[ObservedBy(LikeObserver::class)]
class Like extends MorphPivot {
    /**
     * Таблица не выводится из имени класса: Like дал бы likes.
     *
     * @var string
     */
    protected $table = 'likeables';

    /**
     * У likeables есть собственный id() — значит ключ автоинкрементный.
     * У обычного pivot его нет, и по умолчанию Laravel считает иначе.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Что лайкнули: пост, комментарий или изображение.
     */
    public function likeable(): MorphTo {
        return $this->morphTo();
    }

    /**
     * Кто лайкнул.
     */
    public function profile(): BelongsTo {
        return $this->belongsTo(Profile::class);
    }
}
