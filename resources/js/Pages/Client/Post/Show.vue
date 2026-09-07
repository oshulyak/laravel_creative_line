<template>
    <Head :title="postData.title" />

    <Link
        :href="route('client.feed.index')"
        class="mb-4 inline-block text-sm text-sky-700 hover:underline"
    >
        ← В ленту
    </Link>

    <article class="rounded-lg bg-white p-6 shadow">
        <h1 class="mb-2 text-2xl font-semibold text-gray-900">{{ postData.title }}</h1>

        <p class="mb-4 text-sm text-gray-500">
            {{ postData.author?.nickname ?? 'Аноним' }} ·
            {{ postData.category?.title ?? 'Без категории' }}
        </p>

        <div v-if="postData.images?.length" class="mb-4 grid grid-cols-3 gap-2">
            <img
                v-for="image in postData.images"
                :key="image.id"
                :src="image.url"
                :alt="postData.title"
                class="h-40 w-full rounded object-cover"
            />
        </div>

        <!-- whitespace-pre-line сохраняет переносы строк, набранные в textarea. -->
        <p class="whitespace-pre-line text-gray-700">{{ postData.content }}</p>

        <ul v-if="postData.tags?.length" class="mt-4 flex flex-wrap gap-2">
            <li
                v-for="tag in postData.tags"
                :key="tag.id"
                class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600"
            >
                #{{ tag.title }}
            </li>
        </ul>

        <!--
            Кнопка, а не ссылка: действие меняет данные. type="button" обязателен —
            внутри формы кнопка по умолчанию сабмитит её.

            :disabled на время запроса — не косметика: в likeables стоит
            unique(profile_id, likeable_type, likeable_id), и два быстрых клика подряд
            могут разойтись в гонке и уронить вставку нарушением уникальности.

            aria-pressed сообщает скринридеру состояние переключателя: у иконки-сердечка
            нет текста, по которому это было бы понятно.
        -->
        <button
            type="button"
            :disabled="isLikePending"
            :aria-pressed="postData.is_liked"
            class="mt-6 inline-flex items-center gap-2 text-sm disabled:opacity-50"
            :class="postData.is_liked ? 'text-rose-600' : 'text-gray-400 hover:text-rose-500'"
            @click="toggleLike"
        >
            <!--
                Одна иконка на оба состояния: заливка переключается атрибутом fill.
                currentColor означает «цвет текста кнопки» — цвет задаёт класс выше,
                и SVG о нём ничего не знает.
            -->
            <svg
                class="h-6 w-6"
                viewBox="0 0 24 24"
                :fill="postData.is_liked ? 'currentColor' : 'none'"
                stroke="currentColor"
                stroke-width="1.5"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"
                />
            </svg>

            <span>{{ postData.likes_count }}</span>
        </button>
    </article>
</template>

<script>
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link },
    props: {
        // required: true, а не default: страница без поста не имеет смысла.
        post: {
            type: Object,
            required: true,
        },
    },
    data() {
        return {
            // Локальная копия пропса: после лайка меняются is_liked и likes_count,
            // а пропсы во Vue односторонние — писать в них нельзя. Копия поверхностная,
            // и этого достаточно: мы меняем только два скалярных поля, а вложенные
            // images/tags не трогаем.
            postData: { ...this.post },
            // Блокировка кнопки на время запроса — от двойных кликов.
            isLikePending: false,
        };
    },
    methods: {
        toggleLike() {
            this.isLikePending = true;

            // Тела у запроса нет — второй аргумент axios.post() не нужен вовсе.
            // CSRF-заголовок axios подставит сам из куки XSRF-TOKEN: запрос уходит
            // на свой домен, а маршрут лежит в группе web.
            //
            // route() даёт только URL, глагол задаёт вызов axios: перепутаете post
            // с get — получите 405.
            axios
                .post(route('client.posts.likes.toggle', this.postData.id))
                .then((res) => {
                    // Оба значения берём из ответа, а не считаем на клиенте: пока страница
                    // была открыта, пост могли лайкнуть другие, и локальный ++ разошёлся бы
                    // с базой. Сервер — единственный источник правды.
                    this.postData.is_liked = res.data.is_liked;
                    this.postData.likes_count = res.data.likes_count;
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                // Кнопка должна ожить и после успеха, и после ошибки.
                .finally(() => {
                    this.isLikePending = false;
                });
        },
    },
};
</script>
