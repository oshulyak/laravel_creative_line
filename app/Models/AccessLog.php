<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Запись журнала запросов: одна строка на HTTP-запрос.
 *
 * Пишет её только LoggerMiddleware. Связи user() нет: читать журнал
 * через Eloquent пока некому, а колонка user_id для SQL-запросов уже есть.
 */
class AccessLog extends Model {
    /**
     * created_at и updated_at в таблице нет: время запроса лежит в datetime.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'datetime',
        'module',
        'user_id',
        'data',
    ];

    /**
     * data — массив в PHP и JSON в базе. Каст array сам сделает json_encode
     * при записи и json_decode при чтении.
     *
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'datetime' => 'datetime',
            'data' => 'array',
        ];
    }
}
