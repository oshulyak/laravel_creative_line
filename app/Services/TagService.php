<?php

namespace App\Services;

use App\Models\Tag;
use Illuminate\Support\Collection;

class TagService {
    /**
     * Превращает список названий в модели тегов, создавая недостающие.
     *
     * firstOrCreate() ищет тег по title и создаёт его, только если не нашёл, — именно
     * то поведение, которое нужно общему справочнику: второй пост с тегом «laravel»
     * переиспользует существующую строку, а не плодит копии.
     *
     * @param  list<string>  $titles
     * @return Collection<int, Tag>
     */
    public static function storeBatch(array $titles): Collection {
        return collect($titles)->map(
            fn (string $title): Tag => Tag::firstOrCreate(['title' => $title]),
        );
    }
}
