<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Statistic\StatisticResource;
use App\Models\Statistic;
use Inertia\Response;

class DashboardController extends Controller {
    /**
     * Сводная страница админки: статистика по дням.
     *
     * Страница ничего не считает сама — она читает готовые строки, которые каждую
     * ночь записывает команда statistics:aggregate. Дорогие count() по всем таблицам
     * выполняются раз в сутки в фоне, а не при каждом открытии админки.
     *
     * Последние 30 дней, новые сверху. Пагинации нет: строка в таблице одна на день,
     * месяц помещается на экран целиком. Сортировка по date, а не по id: дата — смысл
     * строки, и уникальный индекс по ней заодно ускоряет эту сортировку.
     */
    public function index(): Response {
        $statistics = Statistic::query()
            ->latest('date')
            ->limit(30)
            ->get();

        return inertia('Admin/Dashboard/Index', [
            'statistics' => StatisticResource::collection($statistics)->resolve(),
        ]);
    }
}
