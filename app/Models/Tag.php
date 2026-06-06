<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model {
    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
    ];

    /**
     * Публикации с этим тегом (многие ко многим через post_tag).
     */
    public function posts(): BelongsToMany {
        return $this->belongsToMany(Post::class)->withTimestamps();
    }
}
