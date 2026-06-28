<?php

namespace App\Console\Commands;

use App\Models\Tag;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Signature('my:test {--demoLog : Запустить демонстрацию файлового логирования моделей}')]
#[Description('Демонстрирует файловое логирование моделей через HasLog.')]
class MyTest extends Command {
    /**
     * Запускает выбранные демонстрационные сценарии.
     */
    public function handle(): int {
        if ($this->option('demoLog')) {
            return $this->demoLog();
        }

        $this->warn('Для демонстрации файлового логирования запустите: php artisan my:test --demoLog');

        return self::SUCCESS;
    }

    /**
     * Запускает полный сценарий проверки нового механизма логирования.
     */
    private function demoLog(): int {
        $this->info('Проверяем новый механизм: модельные события -> HasLog -> Log::build() -> storage/logs/tag/{event}.log');

        $this->clearLogs();
        $this->line('Папки модельных логов в storage/logs очищены.');

        $tag = Tag::create([
            'title' => 'HasLog demo created '.Str::uuid(),
        ]);

        $tag->update([
            'title' => 'HasLog demo updated '.Str::uuid(),
        ]);

        $retrievedTag = Tag::query()->findOrFail($tag->id);
        $retrievedTag->delete();

        $this->info('Демонстрация выполнена для '.Tag::class.'#'.$tag->id.'.');
        $this->line('Сработали события: created, updated, retrieved, deleted.');
        $this->line('Файлы логов созданы автоматически через HasLog. Посмотрите storage/logs.');

        return self::SUCCESS;
    }

    private function clearLogs(): void {
        $logsPath = storage_path('logs');

        if (! File::exists($logsPath)) {
            return;
        }

        foreach (File::directories($logsPath) as $directory) {
            File::deleteDirectory($directory);
        }
    }
}
