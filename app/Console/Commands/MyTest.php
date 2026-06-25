<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Comment;
use App\Models\File;
use App\Models\Image;
use App\Models\Log;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Tag;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('my:test {--ddLog : Запустить логируемые события Post и вывести созданные Log-записи} {--ddHasLog : Запустить логируемые события модели с HasLog} {--ddbootHasLog : Показать совместную работу bootHasLog трейта и booted модели} {--ddMorph : Вывести демонстрацию полиморфных связей}')]
#[Description('Демонстрирует учебные блоки через dd().')]
class MyTest extends Command {
    /**
     * Запускает выбранный учебный блок.
     */
    public function handle(): void {
        if ($this->option('ddLog')) {
            $this->ddLog();
        }

        if ($this->option('ddHasLog')) {
            $this->ddHasLog();
        }

        if ($this->option('ddbootHasLog')) {
            $this->ddbootHasLog();
        }

        if ($this->option('ddMorph')) {
            $this->ddMorph();
        }

        $this->warn('Укажите одну из опций: --ddLog, --ddHasLog, --ddbootHasLog или --ddMorph.');
    }

    /**
     * Создаёт, обновляет, получает и удаляет Post, чтобы PostObserver записал
     * события created, updated, retrieved и deleted в таблицу logs.
     */
    private function ddLog(): void {
        $lastLogId = Log::query()->max('id') ?? 0;

        $post = Post::factory()->create([
            'title' => 'Log demo created '.Str::uuid(),
        ]);

        $post->update([
            'title' => 'Log demo updated '.Str::uuid(),
        ]);

        $retrievedPost = Post::query()->findOrFail($post->id);
        $retrievedPost->delete();

        $logs = $this->logsCreatedAfter($lastLogId);

        dd('PostObserver log events', $logs);
    }

    /**
     * Создаёт, обновляет, получает и удаляет Tag, чтобы трейт HasLog
     * записал события created, updated, retrieved и deleted в таблицу logs.
     */
    private function ddHasLog(): void {
        $lastLogId = Log::query()->max('id') ?? 0;

        $this->runTagLogDemo();

        $logs = $this->logsCreatedAfter($lastLogId);

        dd('HasLog log events', $logs);
    }

    /**
     * Создаёт, обновляет, получает и удаляет Category. В этом блоке одновременно
     * срабатывают bootHasLog() из трейта и booted() из самой модели Category.
     */
    private function ddbootHasLog(): void {
        $lastLogId = Log::query()->max('id') ?? 0;

        $this->runCategoryLogDemo();

        $logs = $this->logsCreatedAfter($lastLogId);

        dd('bootHasLog + Category::booted log events', $logs);
    }

    private function runCategoryLogDemo(): void {
        $category = Category::create([
            'title' => 'Log demo category created '.Str::uuid(),
        ]);

        $category->update([
            'title' => 'Log demo category updated '.Str::uuid(),
        ]);

        $retrievedCategory = Category::query()->findOrFail($category->id);
        $retrievedCategory->delete();
    }

    private function runTagLogDemo(): void {
        $tag = Tag::create([
            'title' => 'Log demo tag created '.Str::uuid(),
        ]);

        $tag->update([
            'title' => 'Log demo tag updated '.Str::uuid(),
        ]);

        $retrievedTag = Tag::query()->findOrFail($tag->id);
        $retrievedTag->delete();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function logsCreatedAfter(int $lastLogId): array {
        return Log::query()
            ->where('id', '>', $lastLogId)
            ->orderBy('id')
            ->get()
            ->map(fn (Log $log): array => [
                'id' => $log->id,
                'model' => $log->model,
                'action' => $log->action,
                'old_attributes' => $log->old_attributes,
                'new_attributes' => $log->new_attributes,
                'changed_attributes' => $log->changed_attributes,
                'created_at' => $log->created_at,
            ])
            ->all();
    }

    /**
     * Достаёт из базы записи со связанными данными и выводит результат каждой
     * morph-связи через dd(). Для каждой группы показана и «прямая» сторона
     * (morphOne/morphMany/morphToMany), и обратная (morphTo) — кто полиморфный
     * родитель. Данные предполагаются заполненными сидерами (`php artisan db:seed`).
     */
    private function ddMorph(): void {
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
