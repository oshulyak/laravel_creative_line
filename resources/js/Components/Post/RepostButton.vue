<template>
    <!--
        Корень один: кнопка и модалка — два узла, и «лишним» атрибутам родителя
        иначе некуда приземлиться. Обёртка inline-flex, чтобы div не растягивался
        на всю строку футера карточки.
    -->
    <div class="inline-flex">
        <button
            type="button"
            class="inline-flex items-center gap-2 text-gray-400 hover:text-emerald-600"
            @click="openModal"
        >
            <svg
                class="h-5 w-5"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M19.5 12c0-1.232-.046-2.453-.138-3.662a4.006 4.006 0 0 0-3.7-3.7 48.678 48.678 0 0 0-7.324 0 4.006 4.006 0 0 0-3.7 3.7c-.017.22-.032.441-.046.662M19.5 12l3-3m-3 3-3-3m-12 3c0 1.232.046 2.453.138 3.662a4.006 4.006 0 0 0 3.7 3.7 48.656 48.656 0 0 0 7.324 0 4.006 4.006 0 0 0 3.7-3.7c.017-.22.032-.441.046-.662M4.5 12l3 3m-3-3-3 3"
                />
            </svg>

            <span>{{ count }}</span>
        </button>

        <!--
            Modal.vue пришёл с Breeze и уже лежит в Components — писать своё
            модальное окно незачем. Он умеет ровно то, что просит задание:
            клик по подложке закрывает окно, Escape тоже.

            Почему клик ВНУТРИ окна его не закрывает и @click.stop не нужен:
            подложка и панель в Modal.vue — СОСЕДИ, а не вложенные элементы.
            Клик по панели просто не проходит через подложку, останавливать
            всплытие нечего. Это разница между «перекрыть экран отдельным слоем»
            и «положить окно внутрь затемнённого блока» — во втором случае
            .stop был бы обязателен.

            show — проп, close — событие: окно не закрывает себя само, оно
            сообщает о намерении, а состоянием владеет наш компонент.
        -->
        <Modal :show="isModalShown" max-width="md" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">Репост публикации</h2>

                <p class="mt-1 truncate text-sm text-gray-500">«{{ post.title }}»</p>

                <!--
                    Заголовок обязателен и должен быть свободен: title в posts
                    уникален, и скопировать его у оригинала нельзя.

                    @keyup.enter — модификатор клавиши: отправка с клавиатуры
                    без оборачивания в <form>. Формы здесь нет намеренно —
                    вложить её внутрь <dialog> можно, но у диалога своё поведение
                    при submit, и разбираться с ним ради одного поля незачем.
                -->
                <input
                    v-model="title"
                    type="text"
                    maxlength="255"
                    placeholder="Заголовок репоста"
                    class="mt-4 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                    @keyup.enter="submit"
                />

                <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100"
                        @click="closeModal"
                    >
                        Отмена
                    </button>

                    <button
                        type="button"
                        :disabled="isSending || !title.trim()"
                        class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
                        @click="submit"
                    >
                        {{ isSending ? 'Отправляю…' : 'Repost' }}
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>

<script>
import axios from 'axios';
import Modal from '@/Components/Modal.vue';

export default {
    name: 'RepostButton',
    components: { Modal },
    props: {
        // Берём пост целиком, а не готовый url, как у LikeButton и CommentForm.
        // Те обобщались потому, что обслуживают двух разных родителей (пост
        // и комментарий); репостят только пост. Плюс модалке нужен заголовок
        // оригинала — прецедент тот же, что у DeletePost.
        post: {
            type: Object,
            required: true,
        },
        // Приставка initial — конвенция для «пропса, с которого начинается
        // локальное состояние». default нужен: там, где withCount() не звали,
        // ключа reposts_count в JSON не будет вовсе.
        initialCount: {
            type: Number,
            default: 0,
        },
    },
    emits: ['reposted'],
    data() {
        return {
            isModalShown: false,
            title: '',
            error: '',
            isSending: false,
            count: this.initialCount,
        };
    },
    watch: {
        // Та же причина, что у LikeButton: data() выполняется один раз, а при
        // частичной перезагрузке списка Vue переиспользует экземпляры карточек.
        initialCount(value) {
            this.count = value;
        },
    },
    methods: {
        openModal() {
            this.error = '';
            this.isModalShown = true;
        },
        /**
         * Пока запрос в полёте, окно не закрываем: иначе пользователь не увидит
         * ни ошибки, ни результата, а введённый заголовок потеряется.
         */
        closeModal() {
            if (this.isSending) {
                return;
            }

            this.isModalShown = false;
        },
        submit() {
            this.isSending = true;
            // Старую ошибку убираем до запроса, иначе она провисит до ответа
            // и будет выглядеть реакцией на новую отправку.
            this.error = '';

            axios
                .post(route('client.posts.reposts.store', this.post.id), {
                    title: this.title,
                })
                .then((res) => {
                    // Счётчик берём из ответа, а не считаем на клиенте: сервер —
                    // единственный источник правды.
                    this.count = res.data.reposts_count;
                    // Поле чистим только после успеха: при 422 текст должен
                    // остаться, пользователь его правит, а не набирает заново.
                    this.title = '';
                    this.isModalShown = false;

                    this.$emit('reposted');
                })
                .catch((e) => {
                    // 422 приходит как { message, errors: { title: [...] } }.
                    // Сюда попадает и «Такое название уже занято» — самая частая
                    // ошибка этой формы.
                    this.error =
                        e.response?.data?.errors?.title?.[0] ?? 'Не удалось сделать репост.';
                })
                .finally(() => {
                    this.isSending = false;
                });
        },
    },
};
</script>
