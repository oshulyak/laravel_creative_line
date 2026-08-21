<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    /**
     * Удаляет изображения поста: строки — сразу, файлы — после успешного коммита.
     *
     * @param  list<int>  $imageIds
     */
    public static function deleteBatch(array $imageIds, Post $post): void {
        // Ранний выход: без него ушёл бы бессмысленный select ... where id in ().
        // Строгое сравнение с пустым массивом читается однозначнее, чем empty().
        if ($imageIds === []) {
            return;
        }

        // Выбираем через связь поста, а не Image::whereIn(...): чужой id просто
        // не попадёт в выборку, даже если правило валидации кто-то ослабит.
        $images = $post->images()->whereIn('id', $imageIds)->get();

        $paths = $images->pluck('img_path')->all();

        // Удаляем по одной модели, а не запросом ->delete(): так срабатывают события
        // Eloquent, а вместе с ними логирование из трейта HasLog. each->delete() —
        // higher order message, сокращение для each(fn (Image $image) => $image->delete()).
        $images->each->delete();

        // Файлы стираем только после коммита. Сделай мы это сразу — откат транзакции
        // вернул бы строки в БД, но не файлы с диска: в списке появились бы битые картинки.
        // Вне транзакции afterCommit() выполняет колбэк немедленно.
        // Storage::delete() принимает массив и молча пропускает несуществующие пути.
        DB::afterCommit(fn () => Storage::disk('public')->delete($paths));
    }
}
