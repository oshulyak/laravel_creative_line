<?php

namespace App\Models;

use App\Models\Traits\HasLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model {
    use HasLog;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
    ];

    /**
     * Пользователи с этой ролью (многие ко многим через role_user).
     */
    public function users(): BelongsToMany {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
