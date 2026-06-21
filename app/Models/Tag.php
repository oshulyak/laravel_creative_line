<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

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
     * Публикации с этим тегом (Taggable: многие ко многим через taggables).
     */
    public function posts(): MorphToMany {
        return $this->morphedByMany(Post::class, 'taggable')->withTimestamps();
    }

    /**
     * Комментарии с этим тегом (Taggable: многие ко многим через taggables).
     */
    public function comments(): MorphToMany {
        return $this->morphedByMany(Comment::class, 'taggable')->withTimestamps();
    }
}
