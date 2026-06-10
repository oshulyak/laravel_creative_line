<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model {
    /** @use HasFactory<TagFactory> */
    use HasFactory;

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
