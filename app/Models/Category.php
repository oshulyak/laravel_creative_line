<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Category extends Model {
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
    ];

    /**
     * Публикации этой категории.
     */
    public function posts(): HasMany {
        return $this->hasMany(Post::class);
    }

    /**
     * Комментарии ко всем постам этой категории (Category → Post → Comment).
     */
    public function comments(): HasManyThrough {
        return $this->hasManyThrough(Comment::class, Post::class);
    }
}
