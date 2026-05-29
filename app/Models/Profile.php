<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model {
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'nickname',
        'first_name',
        'second_name',
        'img_path',
        'birth_date',
        'gender',
        'city',
    ];
}
