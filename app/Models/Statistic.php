<?php

namespace App\Models;

use Database\Factories\StatisticFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Статистика за день: накопленные итоги по всей базе на дату.
 *
 * Связей у модели нет и не будет: это не сущность предметной области,
 * а снимок чисел. Строки пишет только команда statistics:aggregate.
 */
class Statistic extends Model {
    /** @use HasFactory<StatisticFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'posts_count',
        'reposts_count',
        'comments_count',
        'likes_count',
        'views_count',
        'likes_to_views_ratio',
        'likes_to_comments_ratio',
    ];

    /**
     * date — Carbon, как и любая дата в проекте; в ресурсе он превращается
     * в строку 2026-09-13.
     *
     * decimal:4 отдаёт отношения строкой с ровно четырьмя знаками ("0.1000")
     * независимо от того, в каком виде их вернул драйвер базы. null каст не трогает.
     *
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'date' => 'date',
            'likes_to_views_ratio' => 'decimal:4',
            'likes_to_comments_ratio' => 'decimal:4',
        ];
    }
}
