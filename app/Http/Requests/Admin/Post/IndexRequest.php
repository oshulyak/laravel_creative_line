<?php

namespace App\Http\Requests\Admin\Post;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest {
    /**
     * Генератор ставит здесь false — это топ-1 причина внезапного 403 при первом же заходе
     * на страницу списка. Полноценная проверка прав появится вместе с ролями и политиками.
     */
    public function authorize(): bool {
        return true;
    }

    /**
     * Query-параметры админского списка постов, сгруппированные в два блока:
     * filters — условия отбора, pagination — нарезка на страницы.
     *
     * Точка в имени ключа — путь к вложенному значению: правило filters.title проверяет
     * $data['filters']['title']. Тот же синтаксис, что у Arr::get() и $request->input().
     *
     * Правила на сами контейнеры ('filters' => array) — это проверка типа: без них
     * ?filters=строка доехал бы до фильтра. Присутствия ключа в validated() они при этом
     * НЕ гарантируют: Validator::validated() пропускает контейнер, если у него есть правило
     * array и объявлены вложенные правила (флаг excludeUnvalidatedArrayKeys, включённый
     * в Validation\Factory по умолчанию). В результат попадают только реально пришедшие
     * вложенные ключи — поэтому пустая форма отдаёт validated() вообще без ключа filters.
     *
     * Всё nullable, ничего required: пустая форма должна отдавать полный список, а не 422.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            'filters' => ['nullable', 'array'],
            'filters.title' => ['nullable', 'string', 'max:255'],
            // <input type="date"> шлёт «2026-06-01» — без времени, в отличие от API.
            'filters.published_at_from' => ['nullable', 'date_format:Y-m-d'],
            // min:0, а не min:1: «лайков не меньше нуля» — валидный, пусть и бесполезный запрос.
            'filters.likes_from' => ['nullable', 'integer', 'min:0'],

            'pagination' => ['nullable', 'array'],
            // min:1 — не косметика: контроллер передаёт номер страницы в paginate() явно,
            // а значит, встроенная защита резолвера не работает, и page=0 дал бы
            // «OFFSET must not be negative» от PostgreSQL.
            'pagination.page' => ['nullable', 'integer', 'min:1'],
            // Верхняя граница обязательна везде, где размер страницы задаёт клиент:
            // ?per_page=1000000 — это бесплатный способ запросить всю таблицу одним куском.
            'pagination.per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Значения по умолчанию для пагинации.
     *
     * Хук вызывается до запуска валидатора, поэтому merge() дописывает ключи в данные
     * запроса, и правила проверяют уже дополненный набор. Именно поэтому контроллер читает
     * pagination.page и pagination.per_page без ?? — раз оба вложенных ключа всегда есть
     * во входных данных, они всегда попадут и в validated().
     *
     * С filters так не выйдет: значений по умолчанию у фильтров нет, вложенные ключи
     * приходят или не приходят, и на пустой форме блока в validated() не будет вовсе
     * (см. комментарий к rules()). Пустой filters здесь всё равно записываем — он нужен
     * правилу array, чтобы отличать «не прислали» от «прислали мусор».
     *
     * input() с точечной нотацией, а не $this->pagination['page'] ?? 1: когда блока
     * pagination в запросе нет, второй вариант даёт warning об обращении к элементу null.
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'filters' => $this->input('filters', []),
            // pagination пишется целиком: значение по умолчанию должно лечь ровно туда,
            // где его ищет правило pagination.page, а не ключом верхнего уровня.
            'pagination' => [
                'page' => $this->input('pagination.page', 1),
                'per_page' => $this->input('pagination.per_page', 5),
            ],
        ]);
    }
}
