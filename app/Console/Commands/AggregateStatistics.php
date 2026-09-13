<?php

namespace App\Console\Commands;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Statistic;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('statistics:aggregate')]
#[Description('Записывает накопленные итоги по постам, комментариям, лайкам, просмотрам и репостам на сегодняшнюю дату.')]
class AggregateStatistics extends Command {
    /**
     * Считает итоги по всей базе и сохраняет их строкой на сегодняшнюю дату.
     *
     * Запускается по расписанию (routes/console.php, каждую ночь в 02:56),
     * но это обычная artisan-команда: её можно вызвать и руками —
     * php artisan statistics:aggregate.
     */
    public function handle(): int {
        // Накопленный итог: сколько всего строк в таблицах на момент запуска,
        // без фильтра по статусу и по дате. count() и sum() считает база —
        // в PHP не загружается ни одной модели.
        //
        // Репосты — не отдельная таблица, а посты с parent_id, поэтому они входят
        // и в posts_count, и отдельно в reposts_count.
        $postsCount = Post::query()->count();
        $repostsCount = Post::query()->whereNotNull('parent_id')->count();

        // Комментарии вместе с ответами: ответ — такая же строка в comments.
        $commentsCount = Comment::query()->count();

        // Модели лайка в проекте нет: лайк — строка полиморфной pivot-таблицы
        // likeables (посты и комментарии вместе). Считаем её через DB::table().
        $likesCount = DB::table('likeables')->count();

        // sum() на пустой таблице вернул бы null — (int) превращает его в 0.
        $viewsCount = (int) Post::query()->sum('views_count');

        // updateOrCreate(): первый аргумент — по чему искать, второй — что записать.
        // Строки за сегодня нет — создаст, есть — обновит. Повторный запуск в тот же
        // день не плодит дубли, а строки прошлых дней не трогает: у них другая дата.
        //
        // today(), а не now(): колонка хранит день, время ей не нужно.
        $statistic = Statistic::updateOrCreate(
            ['date' => today()],
            [
                'posts_count' => $postsCount,
                'reposts_count' => $repostsCount,
                'comments_count' => $commentsCount,
                'likes_count' => $likesCount,
                'views_count' => $viewsCount,
                'likes_to_views_ratio' => $this->ratio($likesCount, $viewsCount),
                'likes_to_comments_ratio' => $this->ratio($likesCount, $commentsCount),
            ],
        );

        $this->info("Статистика за {$statistic->date->toDateString()} сохранена.");

        return self::SUCCESS;
    }

    /**
     * Отношение двух счётчиков или null, если делить не на что.
     *
     * Без проверки ноль просмотров на свежей базе уронил бы всю команду
     * с DivisionByZeroError, и за эту ночь статистики не было бы вовсе.
     */
    private function ratio(int $numerator, int $denominator): ?float {
        if ($denominator === 0) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }
}
