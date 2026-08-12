<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Response;

class DashboardController extends Controller {
    /**
     * Сводная страница админки.
     *
     * Модели и ресурса у неё нет: это не сущность, а агрегация значений
     * из разных сущностей, поэтому props пока не передаём.
     */
    public function index(): Response {
        return inertia('Admin/Dashboard/Index');
    }
}
