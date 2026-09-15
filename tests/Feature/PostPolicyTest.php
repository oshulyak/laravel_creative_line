<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostPolicyTest extends TestCase {
    use RefreshDatabase;

    /**
     * Лишний пользователь в начале нужен, чтобы id пользователя и id профиля
     * автора разошлись. Иначе фабрики дадут обоим одинаковый id, и политика,
     * сравнивающая author_id с $user->id вместо профиля, прошла бы тест.
     */
    public function test_author_can_delete_own_post(): void {
        User::factory()->create();
        $author = Profile::factory()->create();
        $post = Post::factory()->for($author, 'author')->create();

        $this->assertTrue($author->user->can('delete', $post));
    }

    public function test_other_profile_cannot_delete_post(): void {
        $post = Post::factory()->create();
        $stranger = Profile::factory()->create();

        $this->assertFalse($stranger->user->can('delete', $post));
    }

    /**
     * Без ?-> в политике тест упадёт с «Attempt to read property "id" on null».
     */
    public function test_user_without_profile_cannot_delete_post(): void {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->assertFalse($user->can('delete', $post));
    }
}
