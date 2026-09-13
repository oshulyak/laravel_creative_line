<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ежедневная статистика для админки.
//
// Это декларация, а не запуск: команда выполнится, только если раз в минуту
// кто-то зовёт schedule:run — cron на проде, schedule:work локально.
//
// Первый аргумент — сигнатура команды, ровно то, что пишут после php artisan.
//
// 02:56 — по часовому поясу приложения (UTC из config/app.php), а не по местному
// времени. Для местного понадобился бы ->timezone('Asia/Yekaterinburg').
//
// withoutOverlapping() — страховка на будущее: если подсчёт однажды затянется,
// следующий запуск не начнётся поверх ещё идущего. Блокировка хранится в кэше.
Schedule::command('statistics:aggregate')
    ->dailyAt('02:56')
    ->withoutOverlapping();
