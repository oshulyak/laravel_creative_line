<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PostService {
    /**
     * Создание поста вместе с изображениями и тегами.
     *
     * Метод ничего не знает про HTTP и про текущего пользователя: author_id и published_at
     * уже лежат в $data, их подмешал StoreRequest::prepareForValidation(). Благодаря этому
     * сервис одинаково работает из контроллера, консольной команды и очереди.
     *
     * @param  array<string, mixed>  $data
     */
    public static function store(array $data): Post {
        // Arr::pull забирает значение и удаляет ключ за один вызов. Удалить обязательно:
        // колонок images и tags в таблице posts нет — Post::create() с ними упадёт.
        $images = Arr::pull($data, 'images', []);
        $tagTitles = Arr::pull($data, 'tags', []);

        // Транзакция делает три записи (пост, images, taggables) одной неделимой операцией:
        // либо сохраняется всё, либо ничего. Иначе после сбоя на тегах в базе остался бы
        // «полупост» с занятым уникальным title, блокирующим повторную отправку формы.
        //
        // Замыкание само делает commit при успехе и rollBack при любом Throwable (не только
        // Exception — TypeError наследуется от Error), а исключение пробрасывает дальше:
        // откатываем, но не проглатываем.
        //
        // Транзакция защищает только БД: файлы, уже записанные Storage::put(), при откате
        // останутся на диске. Уборка мусора — отдельная задача, пока оставляем как есть.
        return DB::transaction(function () use ($data, $images, $tagTitles): Post {
            $post = Post::create($data);

            ImageService::storeBatch($images, $post);

            $tags = TagService::storeBatch($tagTitles);
            // sync() приводит набор связей к переданному списку. Для нового поста хватило бы
            // attach(), но sync() идемпотентен и без изменений переедет в будущий update().
            $post->tags()->sync($tags->pluck('id'));

            // Связи подгружаем внутри: PostResource отдаёт их через whenLoaded().
            return $post->load(['category', 'images', 'tags']);
        });
    }
}
