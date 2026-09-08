<?php

namespace App\Http\Resources\Post;

use App\Http\Resources\Category\CategoryResource;
use App\Http\Resources\Image\ImageResource;
use App\Http\Resources\Profile\ProfileResource;
use App\Http\Resources\Tag\TagResource;
use App\Models\Category;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource {
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'author_id' => $this->author_id,
            'category_id' => $this->category_id,
            'title' => $this->title,
            'content' => $this->content,
            'img_path' => $this->img_path,
            'status' => $this->status,
            'published_at' => $this->published_at,
            // whenCounted — брат whenLoaded для withCount(): ключ появится в ответе,
            // только если счётчик действительно посчитан. Имя атрибута ресурс выведет
            // сам: likedByProfiles → liked_by_profiles_count. Наружу отдаём короткое
            // likes_count — внутреннее представление и внешний контракт не обязаны совпадать.
            'likes_count' => $this->whenCounted('likedByProfiles'),
            // whenHas — «отдай ключ, только если такой атрибут вообще есть у модели».
            // Атрибут is_liked появляется от withExists() в клиентских контроллерах;
            // в админке его никто не считает, и ключа в ответе не будет.
            //
            // Приведение к bool обязательно: PostgreSQL возвращает настоящий boolean,
            // а MySQL отдал бы 1/0, и клиенту пришлось бы помнить, что «1» — это истина.
            'is_liked' => $this->whenHas('is_liked', fn (mixed $value): bool => (bool) $value),
            // Ответ на вопрос «может ли текущий пользователь удалить этот пост».
            // Правило пока простое — «пост мой», — и живёт оно в одном месте,
            // а не в шаблоне каждой страницы.
            //
            // N+1 здесь нет, хотя выглядит опасно: $request->user() возвращает один
            // и тот же объект User на весь запрос, а связь profile загружается лениво
            // один раз и запоминается на этом объекте. На страницу из десяти постов
            // придётся один дополнительный запрос, а не десять.
            //
            // ?-> дважды: в API-контексте пользователя может не быть вовсе,
            // а у пользователя — профиля.
            //
            // Ключ отдаётся всегда, без whenHas()/whenLoaded(): это дешёвое вычисленное
            // булево, а не связь, ради которой пришлось бы идти в базу. В админском
            // ответе он тоже появится — там его никто не читает.
            //
            // Важно: can_delete — подсказка интерфейсу, а не защита. Кнопку можно
            // не рисовать, а запрос всё равно отправить руками, поэтому то же правило
            // продублировано в контроллере, и настоящая проверка — там.
            'can_delete' => $this->author_id === $request->user()?->profile?->id,
            // whenLoaded: ключ появится в ответе, только если связь уже загружена, —
            // так ресурс не спровоцирует лишний запрос там, где связь не нужна.
            // Вложенный ресурс обязательно разворачиваем ->resolve(): иначе в массиве
            // останется объект ресурса, а Inertia развернёт его сама через toResponse()
            // и добавит обёртку data — на клиенте получится category.data.title.
            // Ленте нужно имя автора, а не только author_id. Связь та же, что в админке,
            // просто там она не грузилась — и ключа в ответе не было.
            'author' => $this->whenLoaded(
                'author',
                fn (Profile $author): array => ProfileResource::make($author)->resolve(),
            ),
            'category' => $this->whenLoaded(
                'category',
                fn (Category $category): array => CategoryResource::make($category)->resolve(),
            ),
            'images' => $this->whenLoaded(
                'images',
                fn (Collection $images): array => ImageResource::collection($images)->resolve(),
            ),
            'tags' => $this->whenLoaded(
                'tags',
                fn (Collection $tags): array => TagResource::collection($tags)->resolve(),
            ),
        ];
    }
}
