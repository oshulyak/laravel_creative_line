<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model {
    public const STATUS_PUBLISHED = 'published';

    public const STATUS_MODERATE = 'moderate';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'parent_id',
        'content',
        'status',
        'published_at',
    ];

    public static function getStatuses(): array {
        return [
            self::STATUS_PUBLISHED => 'Опубликовано',
            self::STATUS_MODERATE => 'На модерации',
        ];
    }
}
