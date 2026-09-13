<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Statistic;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AggregateStatisticsTest extends TestCase {
    use RefreshDatabase;

    public function test_command_saves_today_totals(): void {
        $this->travelTo('2026-09-13 02:56:00');

        // Пост и репост к нему: в posts две строки, одна из них — репост.
        $post = Post::factory()->create(['views_count' => 30]);
        Post::factory()->for($post, 'parent')->create(['views_count' => 10]);

        // Комментарий и ответ на него: в comments тоже две строки.
        $comment = Comment::factory()->for($post, 'commentable')->create();
        Comment::factory()->for($comment, 'commentable')->create();

        // Три лайка посту и один комментарию — все четыре лежат в likeables.
        $post->likedByProfiles()->attach(Profile::factory()->count(3)->create());
        $comment->likedByProfiles()->attach(Profile::factory()->create());

        $this->artisan('statistics:aggregate')->assertSuccessful();

        // sole() заодно проверяет, что строка ровно одна.
        $statistic = Statistic::sole();
        $this->assertSame('2026-09-13', $statistic->date->toDateString());
        $this->assertSame([
            'posts_count' => 2,
            'reposts_count' => 1,
            'comments_count' => 2,
            'likes_count' => 4,
            'views_count' => 40,
            'likes_to_views_ratio' => '0.1000',
            'likes_to_comments_ratio' => '2.0000',
        ], $statistic->only([
            'posts_count',
            'reposts_count',
            'comments_count',
            'likes_count',
            'views_count',
            'likes_to_views_ratio',
            'likes_to_comments_ratio',
        ]));
    }

    public function test_rerun_on_same_day_updates_existing_row(): void {
        $this->travelTo('2026-09-13 02:56:00');
        Post::factory()->create();
        $this->artisan('statistics:aggregate')->assertSuccessful();

        Post::factory()->create();
        $this->artisan('statistics:aggregate')->assertSuccessful();

        $this->assertSame(2, Statistic::sole()->posts_count);
    }

    public function test_previous_days_are_not_overwritten(): void {
        $this->travelTo('2026-09-12 02:56:00');
        Post::factory()->create();
        $this->artisan('statistics:aggregate')->assertSuccessful();

        $this->travelTo('2026-09-13 02:56:00');
        Post::factory()->create();
        $this->artisan('statistics:aggregate')->assertSuccessful();

        $this->assertSame(
            ['2026-09-12' => 1, '2026-09-13' => 2],
            Statistic::query()
                ->oldest('date')
                ->get()
                ->mapWithKeys(fn (Statistic $statistic) => [$statistic->date->toDateString() => $statistic->posts_count])
                ->all(),
        );
    }

    public function test_ratios_are_empty_when_nothing_to_divide_by(): void {
        // Лайк есть, а просмотров и комментариев нет: делить не на что.
        // Числитель не нулевой специально — иначе null нельзя было бы отличить от «0 / x».
        $post = Post::factory()->create();
        $post->likedByProfiles()->attach(Profile::factory()->create());

        $this->artisan('statistics:aggregate')->assertSuccessful();

        $statistic = Statistic::sole();
        $this->assertNull($statistic->likes_to_views_ratio);
        $this->assertNull($statistic->likes_to_comments_ratio);
    }

    /**
     * Расписание — конфигурация проекта, а не поведение фреймворка: опечатка
     * в сигнатуре или времени в routes/console.php ничем другим не проявится,
     * команда просто молча перестанет запускаться.
     */
    public function test_command_is_scheduled_nightly(): void {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains($event->command, 'statistics:aggregate'));

        $this->assertNotNull($event);
        $this->assertSame('56 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
