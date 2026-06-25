<?php

namespace App\Models;

use App\Models\Traits\HasLog;
use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class File extends Model {
    /** @use HasFactory<FileFactory> */
    use HasFactory, HasLog;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'file_path',
    ];

    /**
     * Владелец файла — пост, комментарий или изображение
     * (обратная сторона morphOne/morphMany).
     */
    public function fileable(): MorphTo {
        return $this->morphTo();
    }
}
