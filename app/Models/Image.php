<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Image extends Model {
    /**
     * @var list<string>
     */
    protected $fillable = [
        'img_path',
    ];
}
