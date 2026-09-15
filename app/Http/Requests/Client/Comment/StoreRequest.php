<?php

namespace App\Http\Requests\Client\Comment;

use App\Models\Comment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest {
    /**
     * Генератор ставит здесь false — это топ-1 причина внезапного 403 при отправке формы.
     * Проверка «а может ли этот пользователь комментировать» появится вместе с политиками.
     */
    public function authorize(): bool {
        return true;
    }

    /**
     * Из формы приходит только content. Остальные три ключа подставляет
     * prepareForValidation(), но правила у них есть: required и формат ловят
     * опечатку в коде на 422, а не на 500 от PostgreSQL. exists для значения
     * из уже загруженной модели не нужен — он ничего не ловит.
     *
     * Отдельный неймспейс Client нужен потому, что Api\Comment\StoreRequest уже есть:
     * у формы в браузере другой контракт — она автора не присылает.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // Колонка content — text, у неё нет ограничения длины. 2000 символов —
            // продуктовое решение, а не отражение схемы: комментарий длиной с роман
            // никому не нужен, а поле без верхней границы — это открытая дверь.
            'content' => ['required', 'string', 'max:2000'],
            // exists не нужен: author_id не приходит из формы, его подставляет
            // prepareForValidation() из профиля, который уже загружен из базы.
            // Проверка существования была бы лишним запросом на каждую отправку.
            // required остаётся: у пользователя без профиля здесь null, и это 422.
            'author_id' => ['required', 'integer'],
            'status' => ['required', 'string', Rule::in(array_keys(Comment::getStatuses()))],
            'published_at' => ['required', 'date'],
        ];
    }

    /**
     * Подмешиваем поля, которых нет в форме, до запуска валидации.
     *
     * Порядок работы FormRequest: authorize() → prepareForValidation() → rules() →
     * validated(). Добавленные здесь ключи уже существуют к моменту проверки
     * и попадают в validated() — а значит, доедут до create() в контроллере.
     *
     * merge() ПЕРЕТИРАЕТ пришедшее от клиента значение. Это и есть защита:
     * подставить чужой author_id в тело запроса ничто не мешает, но до правил
     * он не доживёт — на его месте окажется профиль из сессии.
     *
     * Комментарий принадлежит профилю, а не пользователю: comments.author_id
     * ссылается на profiles.id. Отсюда ->profile->id.
     *
     * Оба ?-> страхуют цепочку. Если профиля нет, author_id станет null,
     * правило required вернёт 422 — контроллеру не придётся писать abort_if().
     *
     * Комментарий публикуется сразу: модерация комментариев в проекте пока
     * не заведена, а «отправил и ничего не появилось» — худшее, что можно
     * показать пользователю без объяснений.
     *
     * Заметьте, чего в merge() нет: commentable_id и commentable_type. Их подставит
     * сама связь — $post->comments()->create(...) знает и id родителя, и его класс.
     * Дублировать это в запросе значило бы завести второй источник правды.
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'author_id' => $this->user()?->profile?->id,
            'status' => Comment::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }
}
