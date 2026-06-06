<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Image extends Model {
    /**
     * @var list<string>
     */
    protected $fillable = [
        'post_id',
        'img_path',
    ];

    /**
     * Публикация, которой принадлежит изображение.
     */
    public function post(): BelongsTo {
        return $this->belongsTo(Post::class);
    }
}
