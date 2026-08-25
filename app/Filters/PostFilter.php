<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;

class PostFilter extends AbstractFilter {
    /**
     * Атрибуты Post, по которым разрешена фильтрация.
     *
     * @var list<string>
     */
    protected array $keys = [
        'id',
        'author_id',
        'category_id',
        'category_title',
        'title',
        'content',
        'img_path',
        'status',
        'likes_from',
        'published_at_from',
        'published_at_to',
        'created_at_from',
        'created_at_to',
        'updated_at_from',
        'updated_at_to',
    ];

    protected function id(Builder $builder, int|string $value): void {
        $builder->where('id', (int) $value);
    }

    protected function authorId(Builder $builder, int|string $value): void {
        $builder->where('author_id', (int) $value);
    }

    protected function categoryId(Builder $builder, int|string $value): void {
        $builder->where('category_id', (int) $value);
    }

    protected function categoryTitle(Builder $builder, string $value): void {
        $builder->whereRelation('category', 'title', 'ilike', "%{$value}%");
    }

    protected function title(Builder $builder, string $value): void {
        $builder->where('title', 'ilike', "%{$value}%");
    }

    protected function content(Builder $builder, string $value): void {
        $builder->where('content', 'ilike', "%{$value}%");
    }

    protected function imgPath(Builder $builder, string $value): void {
        $builder->where('img_path', 'ilike', "%{$value}%");
    }

    protected function status(Builder $builder, int|string $value): void {
        $builder->where('status', (int) $value);
    }

    /**
     * Посты, у которых лайков не меньше указанного числа.
     *
     * Лайк — это строка в likeables, а не колонка в posts, поэтому фильтруем не по полю,
     * а по количеству записей в связи: has() строит коррелированный подзапрос-счётчик.
     *
     * withCount() + having() здесь не подходит: в PostgreSQL HAVING не видит алиасы
     * из SELECT, и запрос упал бы с «column liked_by_profiles_count does not exist».
     */
    protected function likesFrom(Builder $builder, int|string $value): void {
        $builder->has('likedByProfiles', '>=', (int) $value);
    }

    protected function publishedAtFrom(Builder $builder, string $value): void {
        $builder->where('published_at', '>=', $value);
    }

    protected function publishedAtTo(Builder $builder, string $value): void {
        $builder->where('published_at', '<=', $value);
    }

    protected function createdAtFrom(Builder $builder, string $value): void {
        $builder->where('created_at', '>=', $value);
    }

    protected function createdAtTo(Builder $builder, string $value): void {
        $builder->where('created_at', '<=', $value);
    }

    protected function updatedAtFrom(Builder $builder, string $value): void {
        $builder->where('updated_at', '>=', $value);
    }

    protected function updatedAtTo(Builder $builder, string $value): void {
        $builder->where('updated_at', '<=', $value);
    }
}
