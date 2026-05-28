<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model {
    public const STATUS_PUBLISHED = 1;

    public const STATUS_MODERATE = 2;

    public static function getStatuses(): array {
        return [
            self::STATUS_PUBLISHED => 'Опубликовано',
            self::STATUS_MODERATE => 'На модерации',
        ];
    }
}
