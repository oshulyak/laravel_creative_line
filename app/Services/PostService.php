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

    /**
     * Обновление поста вместе с изображениями и тегами.
     *
     * Симметрично store(): те же три шага (колонки, картинки, теги), та же транзакция.
     * Отличие одно — картинки не только добавляются, но и удаляются.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(Post $post, array $data): Post {
        $images = Arr::pull($data, 'images', []);
        $deletedImages = Arr::pull($data, 'deleted_images', []);
        $tagTitles = Arr::pull($data, 'tags', []);

        // Транзакция здесь нужнее, чем в store(): при сбое на тегах откатятся и удаление
        // картинок, и изменение колонок — пользователь увидит ошибку и свой пост
        // в исходном виде, а не наполовину обновлённый.
        return DB::transaction(function () use ($post, $data, $images, $deletedImages, $tagTitles): Post {
            // update() — это fill() + save(): проходит через $fillable, поднимает события
            // модели (а с ними и логирование из HasLog) и обновляет updated_at.
            // Для «тихого» обновления без событий есть updateQuietly(), но события нам нужны.
            $post->update($data);

            // Сначала удаляем, потом добавляем: порядок делает операцию понятной
            // («заменить картинки») и не даёт свежезагруженному файлу попасть под удаление,
            // если в deleted_images случайно приедет id из этого же запроса.
            ImageService::deleteBatch($deletedImages, $post);
            ImageService::storeBatch($images, $post);

            // Вот теперь sync() работает по назначению: снятые в форме теги отвяжутся,
            // новые привяжутся, оставшиеся не будут тронуты. При создании он был просто
            // безопасной привычкой — здесь он несёт всю логику обновления связи.
            $tags = TagService::storeBatch($tagTitles);
            $post->tags()->sync($tags->pluck('id'));

            // Связи подгружаем в конце: PostResource отдаёт их через whenLoaded().
            // Модель приехала из route model binding без загруженных связей, поэтому
            // load() прочитает актуальное состояние — уже без удалённых картинок.
            // Будь связь загружена заранее, load() молча вернул бы устаревшую коллекцию:
            // лечится refresh() или unsetRelation('images')->load('images').
            return $post->load(['category', 'images', 'tags']);
        });
    }
}
