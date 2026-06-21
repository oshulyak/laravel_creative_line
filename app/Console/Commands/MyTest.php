<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Comment;
use App\Models\File;
use App\Models\Image;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Tag;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('my:test')]
#[Description('Демонстрирует полиморфные (morph) связи моделей через dd() (урок 7).')]
class MyTest extends Command {
    /**
     * Достаёт из базы записи со связанными данными и выводит результат каждой
     * morph-связи через dd(). Для каждой группы показана и «прямая» сторона
     * (morphOne/morphMany/morphToMany), и обратная (morphTo) — кто полиморфный
     * родитель. Данные предполагаются заполненными сидерами (`php artisan db:seed`).
     */
    public function handle(): void {
        dd(
            'Imageable (morphOne / morphMany ↔ morphTo)',
            $this->imageable(),

            'Fileable (morphMany / morphOne ↔ morphTo)',
            $this->fileable(),

            'Likeable (morphToMany ↔ morphedByMany)',
            $this->likeable(),

            'Commentable (morphMany ↔ morphTo)',
            $this->commentable(),

            'Taggable (morphToMany ↔ morphedByMany)',
            $this->taggable(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function imageable(): array {
        $category = Category::has('image')->first();   // morphOne
        $profile = Profile::has('image')->first();      // morphOne
        $post = Post::has('images')->first();            // morphMany
        $comment = Comment::has('images')->first();      // morphMany
        $image = Image::with('imageable')->first();      // morphTo (обратная сторона)

        return [
            'Category morphOne image → img_path' => $category?->image?->img_path,
            'Profile morphOne image → img_path' => $profile?->image?->img_path,
            'Post morphMany images → ids' => $post?->images->pluck('id')->all(),
            'Comment morphMany images → ids' => $comment?->images->pluck('id')->all(),
            'Image morphTo imageable → owner' => $image
                ? class_basename($image->imageable_type).'#'.$image->imageable_id
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fileable(): array {
        $post = Post::has('files')->first();         // morphMany
        $comment = Comment::has('files')->first();   // morphMany
        $image = Image::has('file')->first();        // morphOne
        $file = File::with('fileable')->first();     // morphTo (обратная сторона)

        return [
            'Post morphMany files → ids' => $post?->files->pluck('id')->all(),
            'Comment morphMany files → ids' => $comment?->files->pluck('id')->all(),
            'Image morphOne file → file_path' => $image?->file?->file_path,
            'File morphTo fileable → owner' => $file
                ? class_basename($file->fileable_type).'#'.$file->fileable_id
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function likeable(): array {
        $post = Post::has('likedByProfiles')->first();         // morphToMany
        $comment = Comment::has('likedByProfiles')->first();   // morphToMany
        $image = Image::has('likedByProfiles')->first();       // morphToMany
        $profile = Profile::has('likedPosts')->first();        // morphedByMany (обратная сторона)

        return [
            'Post morphToMany likedByProfiles → ids' => $post?->likedByProfiles->pluck('id')->all(),
            'Comment morphToMany likedByProfiles → ids' => $comment?->likedByProfiles->pluck('id')->all(),
            'Image morphToMany likedByProfiles → ids' => $image?->likedByProfiles->pluck('id')->all(),
            'Profile morphedByMany likedPosts → ids' => $profile?->likedPosts->pluck('id')->all(),
            'Profile morphedByMany likedComments → ids' => $profile?->likedComments->pluck('id')->all(),
            'Profile morphedByMany likedImages → ids' => $profile?->likedImages->pluck('id')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commentable(): array {
        $post = Post::has('comments')->first();   // morphMany
        // Ответ в ветке — комментарий, чей commentable это другой комментарий.
        $reply = Comment::with('commentable')
            ->where('commentable_type', Comment::class)
            ->first();                            // morphTo

        return [
            'Post morphMany comments → ids' => $post?->comments->pluck('id')->all(),
            'Reply morphTo commentable → parent' => $reply
                ? class_basename($reply->commentable_type).'#'.$reply->commentable_id
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taggable(): array {
        $post = Post::has('tags')->first();        // morphToMany
        $comment = Comment::has('tags')->first();  // morphToMany
        $tag = Tag::has('posts')->first();         // morphedByMany (обратная сторона)

        return [
            'Post morphToMany tags → titles' => $post?->tags->pluck('title')->all(),
            'Comment morphToMany tags → titles' => $comment?->tags->pluck('title')->all(),
            'Tag morphedByMany posts → ids' => $tag?->posts->pluck('id')->all(),
            'Tag morphedByMany comments → ids' => $tag?->comments->pluck('id')->all(),
        ];
    }
}
