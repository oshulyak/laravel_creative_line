<?php

namespace App\Http\Requests\Admin\Post;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Генератор ставит здесь false — это топ-1 причина внезапного 403 при отправке формы.
     * Полноценная проверка прав появится вместе с ролями и политиками.
     */
    public function authorize(): bool {
        return true;
    }

    /**
     * Правила берутся из схемы posts, а не из головы: title — NOT NULL и unique,
     * content — NOT NULL, published_at — nullable.
     *
     * Отдельный неймспейс Admin нужен потому, что Api\Post\StoreRequest уже есть:
     * у админки свой набор правил (например, автора она не принимает из формы).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            'title' => ['required', 'string', 'max:255', 'unique:posts,title'],
            'content' => ['required', 'string'],
            // Дата больше не приходит из формы, её подставляет prepareForValidation(),
            // поэтому значение обязательное: пост публикуется в момент создания.
            'published_at' => ['required', 'date'],
            // exists нужен даже при внешнем ключе: без него несуществующий id
            // дойдёт до INSERT и станет 500-й от PostgreSQL вместо 422 с сообщением.
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'author_id' => ['required', 'integer', 'exists:profiles,id'],
            'images' => ['nullable', 'array'],
            // images.* — правило для каждого элемента массива. max для файлов считается
            // в килобайтах, то есть 2048 — это 2 МБ (в пределах лимитов php.ini).
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            // Из формы теги приходят строкой, но к моменту rules() prepareForValidation()
            // уже превратил их в массив — проверяем именно массив названий.
            'tags' => ['array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }

    /**
     * Подмешиваем поля, которых нет в форме, до запуска валидации: значения приходят
     * не от клиента, но проверяются теми же правилами.
     *
     * Порядок работы FormRequest: authorize() → prepareForValidation() → rules() → validated(),
     * поэтому добавленные ключи уже существуют к моменту проверки и попадают в validated().
     *
     * Пост принадлежит не пользователю, а его профилю (users → profiles → posts),
     * отсюда ->profile->id, а не ->user()->id.
     *
     * Пользователя берём у самого запроса, а не через хелпер auth(): без аргумента
     * auth() типизирован контрактом Auth\Factory, где объявлены только guard() и
     * shouldUse(). Метод user() в рантайме подставляет магический __call у AuthManager,
     * проксируя вызов на гард по умолчанию, но статический анализ такую магию не видит
     * и подсвечивает вызов как несуществующий. $this->user() объявлен явно в Http\Request
     * и резолвит того же пользователя.
     *
     * Оба ?-> страхуют цепочку: и «гость» (сюда не пустит middleware auth, но проверка
     * бесплатна), и «у пользователя нет профиля» дадут null. Разбор такой ситуации
     * оставлен валидации: правило required вернёт 422, а не 500.
     *
     * published_at — момент создания: пост публикуется сразу, выбора даты в форме нет.
     *
     * Вторая типовая задача prepareForValidation() — нормализовать пришедшее:
     * строка тегов приводится к массиву до того, как её увидят правила.
     */
    protected function prepareForValidation(): void {
        $this->merge([
            'author_id' => $this->user()?->profile?->id,
            'published_at' => now(),
            'tags' => $this->tagTitles(),
        ]);
    }

    /**
     * Разбирает строку тегов в список уникальных названий: "laravel, vue , laravel"
     * превращается в ['laravel', 'vue'].
     *
     * - (string) нужен потому, что поля может не быть в запросе вовсе — input() вернёт null;
     * - trim() не даёт завести в справочнике «vue» и « vue» как два разных тега;
     * - filter() с явным сравнением выкидывает пустые куски от «laravel,,vue» и хвостовой
     *   запятой, но сохраняет формально валидный тег "0", который отбросил бы пустой filter();
     * - unique() — защита от дублей: «laravel, laravel» не должен дважды уехать в sync();
     * - values() сбрасывает ключи, дырявые после filter()/unique(), иначе в JSON
     *   массив превратится в объект.
     *
     * @return list<string>
     */
    private function tagTitles(): array {
        return collect(explode(',', (string) $this->input('tags')))
            ->map(fn (string $title): string => trim($title))
            ->filter(fn (string $title): bool => $title !== '')
            ->unique()
            ->values()
            ->all();
    }
}
