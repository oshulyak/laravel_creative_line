<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageService {
    /**
     * Кладёт файлы на диск и привязывает их к посту через полиморфную связь.
     *
     * Перед записью проверяем, что элемент действительно является успешно загруженным
     * файлом: в массив может приехать пустой элемент или файл, оборвавшийся при аплоаде.
     * Без проверки Storage::put() вернул бы false, и в images ушла бы строка с пустым
     * img_path — битая картинка в списке постов вместо честного пропуска.
     *
     * Сигнатура Post $post — учебное упрощение: методу нужен любой объект со связью
     * images(). Обобщать рано — понадобится, когда появится второй вызывающий.
     *
     * @param  array<int, UploadedFile>  $images
     */
    public static function storeBatch(array $images, Post $post): void {
        foreach ($images as $image) {
            if (! $image instanceof UploadedFile || ! $image->isValid()) {
                continue;
            }

            // put() кладёт файл в storage/app/public/images под сгенерированным
            // уникальным именем и возвращает относительный путь (images/9x2k....jpg).
            // create() через полиморфную связь сам проставит imageable_type/imageable_id.
            $post->images()->create([
                'img_path' => Storage::disk('public')->put('/images', $image),
            ]);
        }
    }
}
