<template>
    <Head title="Мои публикации" />

    <section class="mb-6 rounded-lg bg-white p-5 shadow">
        <h1 class="text-2xl font-semibold text-gray-900">{{ profile.nickname }}</h1>

        <p class="text-sm text-gray-500">{{ fullName }}</p>

        <p class="mt-2 text-sm text-gray-500">Публикаций: {{ posts.meta.total }}</p>
    </section>

    <p v-if="!posts.data.length" class="rounded-lg bg-white p-6 text-sm text-gray-500">
        Вы пока ничего не опубликовали.
    </p>

    <article
        v-for="post in posts.data"
        :key="post.id"
        class="mb-4 rounded-lg bg-white p-5 shadow"
    >
        <Link
            :href="route('client.posts.show', post.id)"
            class="text-lg font-semibold text-gray-900 hover:text-sky-700"
        >
            {{ post.title }}
        </Link>

        <!--
            Свои посты на модерации автор видит (контроллер не фильтрует по статусу),
            и об этом лучше сказать прямо, иначе пост выглядит как обычный.

            Сравнение с числом 1 — шов между сервером и клиентом: константа
            Post::STATUS_PUBLISHED живёт в PHP, а на клиент приезжает голый код статуса.
        -->
        <span
            v-if="post.status !== 1"
            class="ml-2 rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700"
        >
            На модерации
        </span>

        <p class="mt-2 whitespace-pre-line text-sm text-gray-700">
            {{ excerpt(post.content) }}
        </p>

        <p class="mt-3 text-sm text-gray-400">♥ {{ post.likes_count }}</p>
    </article>

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
    name: 'Personal',
    layout: ClientLayout,
    components: { Head, Link },
    props: {
        profile: {
            type: Object,
            required: true,
        },
        posts: {
            type: Object,
            default: () => ({ data: [], meta: { links: [], last_page: 1, total: 0 } }),
        },
    },
    computed: {
        /**
         * Имя и фамилия, если они заполнены: оба поля в profiles nullable.
         * filter(Boolean) выбрасывает null'ы, чтобы не получить строку с пробелом.
         */
        fullName() {
            return (
                [this.profile.first_name, this.profile.second_name]
                    .filter(Boolean)
                    .join(' ') || 'Имя не заполнено'
            );
        },
    },
    methods: {
        excerpt(content, length = 200) {
            return content.length > length ? `${content.slice(0, length)}…` : content;
        },
    },
};
</script>
