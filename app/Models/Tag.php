<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model {
    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
    ];
}
