<template>
    <Head title="Лента" />

    <header class="mb-4 flex items-baseline justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Лента</h1>
        <span class="text-sm text-gray-500">Всего: {{ posts.meta.total }}</span>
    </header>

    <p v-if="!posts.data.length" class="rounded-lg bg-white p-6 text-sm text-gray-500">
        Публикаций пока нет.
    </p>

    <article
        v-for="post in posts.data"
        :key="post.id"
        class="mb-4 rounded-lg bg-white p-5 shadow"
    >
        <!--
            post.author?.nickname, а не post.author.nickname: ключ приходит из whenLoaded()
            и при незагруженной связи его в пропсах вообще нет — обращение к свойству
            у undefined уронит рендер страницы.
        -->
        <p class="mb-1 text-xs uppercase tracking-wider text-gray-400">
            {{ post.author?.nickname ?? 'Аноним' }} ·
            {{ post.category?.title ?? 'Без категории' }}
        </p>

        <!--
            Link, а не <a href>: обычная ссылка перезагрузила бы страницу целиком,
            Link делает XHR и подменяет только компонент страницы.
        -->
        <Link
            :href="route('client.posts.show', post.id)"
            class="text-lg font-semibold text-gray-900 hover:text-sky-700"
        >
            {{ post.title }}
        </Link>

        <p class="mt-2 whitespace-pre-line text-sm text-gray-700">
            {{ excerpt(post.content) }}
        </p>

        <!--
            Лайк в ленте — только индикатор, без клика: ставится он на странице поста.
            Так лента остаётся страницей чтения, а не набором кнопок, меняющих данные.
        -->
        <p class="mt-3 text-sm" :class="post.is_liked ? 'text-rose-600' : 'text-gray-400'">
            ♥ {{ post.likes_count }}
        </p>
    </article>

    <!--
        Пагинация ссылками, а не axios: в ленте нет фильтра, поэтому и локального
        состояния запроса нет — достаточно перейти на /feed?page=2. Link делает
        Inertia-переход, сервер отдаёт новые пропсы, раскладка остаётся на месте.

        link.url, а не link.page: в отличие от админки, номер страницы никуда подставлять
        не нужно — пагинатор уже собрал готовый URL. У «...» и у неактивных стрелок
        url равен null, поэтому такие элементы показываем span'ом.

        v-html вместо {{ }}: в label лежат HTML-сущности &laquo; и &raquo; из языкового
        файла фреймворка, интерполяция показала бы их буквально. Источник строки —
        сам Laravel, а не пользовательский ввод, поэтому XSS тут нет.
    -->
    <nav v-if="posts.meta.last_page > 1" class="mt-6 flex flex-wrap gap-1">
        <template v-for="(link, index) in posts.meta.links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
                class="border px-3 py-2 text-sm"
                :class="
                    link.active
                        ? 'border-sky-800 bg-sky-700 text-white'
                        : 'border-gray-300 bg-white hover:bg-gray-50'
                "
                v-html="link.label"
            />
            <span
                v-else
                class="border border-gray-200 px-3 py-2 text-sm text-gray-300"
                v-html="link.label"
            />
        </template>
    </nav>
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Index',
    // layout — свойство Inertia, а не Vue: при SPA-переходе раскладка не пересоздаётся,
    // меняется только содержимое её <slot />.
    layout: ClientLayout,
    components: { Head, Link },
    props: {
        posts: {
            // Object, а не Array: постраничный ответ — это { data, links, meta }.
            type: Object,
            // Заглушка повторяет форму настоящего ответа: шаблон обращается
            // к meta.total и meta.links.
            default: () => ({ data: [], meta: { links: [], last_page: 1, total: 0 } }),
        },
    },
    methods: {
        /**
         * Короткий анонс поста. Обрезаем на клиенте, потому что на странице поста
         * нужен полный текст, и второй выборки ради превью делать не хочется.
         */
        excerpt(content, length = 200) {
            return content.length > length ? `${content.slice(0, length)}…` : content;
        },
    },
};
</script>
