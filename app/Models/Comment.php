<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model {
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
}
