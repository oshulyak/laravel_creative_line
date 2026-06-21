<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationsThroughTest extends TestCase {
    use RefreshDatabase;

    /**
     * Category::comments() — комментарии ко всем постам категории
     * (Category → Post → Comment). Ключи стандартные.
     */
    public function test_category_returns_comments_of_its_posts(): void {
        // Arrange: категория с постом, у поста — 2 комментария.
        $category = Category::factory()->create();
        $post = Post::factory()->for($category)->create();
        $expectedComments = Comment::factory()->count(2)->for($post, 'commentable')->create();

        // Шум: чужая категория со своим постом и комментарием.
        $foreignPost = Post::factory()->create();
        $foreignComment = Comment::factory()->for($foreignPost, 'commentable')->create();

        // Act.
        $comments = $category->comments;

        // Assert: ровно 2 комментария, и это именно те, что мы создали.
        $this->assertCount(2, $comments);
        $this->assertFalse($comments->contains($foreignComment));
        // Сверяем по id — это надёжнее, чем по тексту (тексты у фабрики случайны).
        $this->assertEqualsCanonicalizing(
            $expectedComments->pluck('id')->all(),
            $comments->pluck('id')->all(),
        );
    }

    /**
     * User::posts() — посты пользователя через его профиль
     * (User → Profile → Post). Второй ключ — author_id.
     */
    public function test_user_returns_posts_through_profile(): void {
        // Arrange: пользователь с профилем, профиль — автор 2 постов.
        $user = User::factory()->hasProfile()->create();
        Post::factory()->count(2)->for($user->profile, 'author')->create();

        // Шум: пост другого пользователя.
        $other = User::factory()->hasProfile()->create();
        $foreignPost = Post::factory()->for($other->profile, 'author')->create();

        $this->assertCount(2, $user->posts);
        $this->assertFalse($user->posts->contains($foreignPost));
    }

    /**
     * User::comments() — комментарии, написанные пользователем через профиль
     * (User → Profile → Comment). Второй ключ — author_id.
     */
    public function test_user_returns_comments_through_profile(): void {
        // Arrange: профиль пользователя — автор 2 комментариев.
        $user = User::factory()->hasProfile()->create();
        Comment::factory()->count(2)->for($user->profile, 'author')->create();

        // Шум: комментарий другого пользователя.
        $other = User::factory()->hasProfile()->create();
        $foreignComment = Comment::factory()->for($other->profile, 'author')->create();

        $this->assertCount(2, $user->comments);
        $this->assertFalse($user->comments->contains($foreignComment));
    }

    /**
     * Profile::postComments() — комментарии К постам профиля от кого угодно
     * (Profile → Post → Comment). Первый ключ — author_id.
     *
     * Ключевой контраст: комментарий, который автор написал САМ, но к чужому
     * посту, сюда НЕ попадает — связь про посты профиля, а не про его комментарии.
     */
    public function test_profile_returns_comments_on_its_posts(): void {
        // Arrange: автор и его пост, на посту — чужой комментарий.
        $author = Profile::factory()->create();
        $post = Post::factory()->for($author, 'author')->create();
        $commentOnHisPost = Comment::factory()->for($post, 'commentable')->create();

        // Шум: комментарий самого автора, но к ЧУЖОМУ посту.
        $foreignPost = Post::factory()->create();
        $commentHeWroteElsewhere = Comment::factory()
            ->for($foreignPost, 'commentable')
            ->for($author, 'author')
            ->create();

        $postComments = $author->postComments;

        $this->assertTrue($postComments->contains($commentOnHisPost));
        $this->assertFalse($postComments->contains($commentHeWroteElsewhere));
    }
}
