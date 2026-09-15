<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPostDestroyTest extends TestCase {
    use RefreshDatabase;

    /**
     * Пост чужой: в админке правила «только свой» нет, решает роль.
     */
    public function test_admin_deletes_any_post(): void {
        $post = Post::factory()->create();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->deleteJson(route('admin.posts.destroy', $post));

        $response->assertNoContent();
        $this->assertModelMissing($post);
    }

    /**
     * Без middleware admin любой вошедший пользователь удалил бы так
     * чужой пост, минуя политику клиентской части.
     */
    public function test_user_without_admin_role_cannot_delete_post(): void {
        $post = Post::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->deleteJson(route('admin.posts.destroy', $post));

        $response->assertForbidden();
        $this->assertModelExists($post);
    }
}
