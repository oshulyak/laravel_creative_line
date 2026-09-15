<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;

/**
 * Создание групп.
 *
 * Как и ChatService, сервис не знает про HTTP и текущего пользователя:
 * создателя ему передают снаружи.
 */
class GroupService {
    /**
     * Группа, в которой создатель сразу состоит.
     *
     * Иначе создатель откроет свою же группу и увидит «Создавать темы
     * могут участники группы».
     *
     * @param  array{title: string, description?: string|null}  $data
     */
    public static function store(array $data, Profile $creator): Group {
        // Группа и участник — вставки в две таблицы. Если вторая упадёт,
        // транзакция не оставит в базе группу, в которой не состоит даже
        // её создатель.
        return DB::transaction(function () use ($data, $creator): Group {
            $group = Group::create($data);

            // attach(), а не toggle(): группа новая, переключать нечего.
            $group->subscribers()->attach($creator->id);

            return $group;
        });
    }
}
