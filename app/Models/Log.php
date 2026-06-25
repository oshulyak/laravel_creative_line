<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Log extends Model {
    /**
     * @var list<string>
     */
    protected $fillable = [
        'model',
        'action',
        'old_attributes',
        'new_attributes',
        'changed_attributes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array {
        return [
            'old_attributes' => 'array',
            'new_attributes' => 'array',
            'changed_attributes' => 'array',
        ];
    }
}
