<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model {
    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
    ];
}
