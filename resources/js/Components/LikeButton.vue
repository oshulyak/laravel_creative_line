<template>
    <button
        type="button"
        :disabled="isPending"
        :aria-pressed="isLiked"
        class="inline-flex items-center gap-2 disabled:opacity-50"
        :class="isLiked ? 'text-rose-600' : 'text-gray-400 hover:text-rose-500'"
        @click="toggle"
    >
        <!--
            Одна иконка на оба состояния: заливка переключается атрибутом fill.
            currentColor означает «цвет текста кнопки» — цвет задаёт класс выше.
        -->
        <svg
            :class="iconClass"
            viewBox="0 0 24 24"
            :fill="isLiked ? 'currentColor' : 'none'"
            stroke="currentColor"
            stroke-width="1.5"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"
            />
        </svg>

        <span>{{ count }}</span>
    </button>
</template>

<script>
import axios from 'axios';

export default {
    name: 'LikeButton',
    props: {
        // Готовый адрес, а не id + имя маршрута: компонент не знает и не должен
        // знать, лайкают через него пост или комментарий. Собрать URL — забота
        // того, кто компонент ставит.
        url: {
            type: String,
            required: true,
        },
        // Начальные значения, дальше кнопка ведёт их сама. Приставка initial —
        // конвенция Vue для «пропса, с которого начинается локальное состояние»:
        // она подсказывает читателю, что дальше значение живёт своей жизнью.
        //
        // default-ы не декоративные: у только что созданного комментария сервер
        // вообще не отдаёт ключей likes_count и is_liked, и в компонент
        // приедет undefined. Vue в таком случае подставит значение по умолчанию.
        initialLiked: {
            type: Boolean,
            default: false,
        },
        initialCount: {
            type: Number,
            default: 0,
        },
        // Размер иконки задаёт родитель: в карточке поста она крупнее, чем
        // под комментарием. Класс, а не число, — чтобы не изобретать свою
        // систему размеров поверх Tailwind.
        iconClass: {
            type: String,
            default: 'h-5 w-5',
        },
    },
    // Сообщаем наружу, чем закончилось переключение. Никому из нынешних
    // родителей это не нужно, но контракт стоит копейку, а страница поста,
    // где счётчик мог бы дублироваться в шапке, появится завтра.
    emits: ['toggled'],
    data() {
        return {
            isLiked: this.initialLiked,
            count: this.initialCount,
            // Блокировка кнопки на время запроса — не косметика: в likeables стоит
            // unique(profile_id, likeable_type, likeable_id), и два быстрых клика
            // могут разойтись в гонке и уронить вставку нарушением уникальности.
            isPending: false,
        };
    },
    watch: {
        // Те же наблюдатели, что в ItemPost, и по той же причине: data() выполняется
        // один раз, а при частичной перезагрузке списка Vue переиспользует
        // существующие экземпляры — без наблюдателя в кнопке остались бы
        // счётчики с первой загрузки страницы.
        initialLiked(value) {
            this.isLiked = value;
        },
        initialCount(value) {
            this.count = value;
        },
    },
    methods: {
        toggle() {
            this.isPending = true;

            // Тела у запроса нет — второй аргумент axios.post() не нужен вовсе.
            // CSRF-заголовок axios подставит сам из куки XSRF-TOKEN.
            axios
                .post(this.url)
                .then((res) => {
                    // Оба значения берём из ответа, а не считаем на клиенте: пока
                    // страница была открыта, лайкнуть мог кто-то ещё, и локальный ++
                    // разошёлся бы с базой. Сервер — единственный источник правды.
                    this.isLiked = res.data.is_liked;
                    this.count = res.data.likes_count;

                    this.$emit('toggled', res.data);
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isPending = false;
                });
        },
    },
};
</script>
