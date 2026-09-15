<?php

namespace App\Http\Requests\Client\Repost;

use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest {
    /**
     * Генератор ставит здесь false — это топ-1 причина внезапного 403 при отправке формы.
     * Проверка «а может ли этот пользователь репостить» появится вместе с политиками.
     */
    public function authorize(): bool {
        return true;
    }

    /**
     * Из модалки приходит только title. Остальное подставляет prepareForValidation(),
     * но правила у этих полей есть: required и формат ловят опечатку в коде на 422,
     * а не на 500 из базы. exists для значения из уже загруженной модели (author_id)
     * ничего не ловит, поэтому его там нет.
     *
     * Отдельный неймспейс Repost, а не Client\Post: у репоста свой контракт формы —
     * одно поле вместо картинок, тегов и категории.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array {
        return [
            // unique — из-за индекса в схеме: posts.title уникален, и скопировать
            // заголовок оригинала нельзя. Именно поэтому модалка его и спрашивает.
            // Без этого правила дубль доехал бы до INSERT и стал 500-й.
            //
            // max:255 — из-за string() без длины в миграции.
            'title' => ['required', 'string', 'max:255', 'unique:posts,title'],
            'content' => ['required', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            // exists не нужен: id берётся из профиля, уже загруженного из базы.
            // required остаётся: у пользователя без профиля здесь null, и это 422.
            'author_id' => ['required', 'integer'],
            'published_at' => ['required', 'date'],
        ];
    }

    /**
     * Подмешиваем поля, которых нет в форме, до запуска валидации.
     *
     * $this->route('post') отдаёт ту же модель Post, что приедет в контроллер:
     * неявную привязку Laravel выполняет до FormRequest, и повторного запроса
     * в базу здесь не будет.
     *
     * content и category_id копируются из оригинала. Колонка content — NOT NULL,
     * значит текст у репоста быть обязан; копия — это снимок: оригинал потом
     * отредактируют, а репост останется прежним. category_id копируем, чтобы
     * в карточке не появилось «Без категории».
     *
     * author_id берём из сессии: подставить чужой id в тело запроса ничто не мешает,
     * но merge() перетрёт его раньше, чем правила его увидят. Пост принадлежит
     * профилю, а не пользователю, отсюда ->profile->id.
     *
     * status не подставляем: у колонки есть DEFAULT (Post::STATUS_PUBLISHED),
     * и админская форма создания поста поступает так же. Репост появляется
     * опубликованным сразу — модерации у клиентских публикаций пока нет.
     *
     * Чего здесь нет: parent_id. Его проставит сама связь — $post->reposts()->create()
     * знает id родителя. Дублировать это значило бы завести второй источник правды.
     */
    protected function prepareForValidation(): void {
        /** @var Post $post */
        $post = $this->route('post');

        $this->merge([
            'content' => $post->content,
            'category_id' => $post->category_id,
            'author_id' => $this->user()?->profile?->id,
            'published_at' => now(),
        ]);
    }
}
