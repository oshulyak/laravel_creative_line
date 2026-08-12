<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PostService {
    /**
     * Создание поста вместе с прикреплёнными изображениями.
     *
     * Метод ничего не знает про HTTP и про текущего пользователя: author_id уже лежит
     * в $data, его подмешал StoreRequest::prepareForValidation(). Благодаря этому сервис
     * одинаково работает из контроллера, консольной команды и очереди.
     *
     * @param  array<string, mixed>  $data
     */
    public static function store(array $data): Post {
        /** @var list<UploadedFile> $images */
        $images = $data['images'] ?? [];

        // unset обязателен: в $data лежат объекты UploadedFile, а колонки images
        // в таблице posts нет — Post::create() с этим ключом упадёт.
        unset($data['images']);

        $post = Post::create($data);

        foreach ($images as $image) {
            // put() кладёт файл в storage/app/public/images под сгенерированным
            // уникальным именем и возвращает относительный путь (images/9x2k....jpg).
            // Уникальное имя важно: два photo.jpg от разных людей не затрут друг друга.
            // create() через полиморфную связь сам проставит imageable_type/imageable_id.
            $post->images()->create([
                'img_path' => Storage::disk('public')->put('/images', $image),
            ]);
        }

        // Подгружаем связь, чтобы PostResource смог отдать images клиенту:
        // whenLoaded() пропускает незагруженные связи молча.
        return $post->load('images');
    }
}
