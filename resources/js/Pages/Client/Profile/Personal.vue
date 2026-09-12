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

    <!--
        Та же карточка, что в ленте: бейдж «На модерации» и обрезка текста переехали
        внутрь компонента. Кнопка удаления появится у всех постов — здесь они свои,
        и can_delete с сервера придёт истинным.
    -->
    <ItemPost v-for="post in posts.data" :key="post.id" :post="post" />

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
import { Head, Link, router } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';
import ItemPost from '@/Components/Post/ItemPost.vue';

export default {
    name: 'Personal',
    layout: ClientLayout,
    components: { Head, Link, ItemPost },
    // Обработчик тот же, что в ленте, и это не случайность: в шапке страницы
    // выводится posts.meta.total, и после перезапроса он обновится сам.
    provide() {
        return {
            onPostDeleted: this.reloadPosts,
            // Именно здесь выполняется последний пункт задания: репост — пост
            // с author_id текущего профиля, personal() выбирает $profile->posts(),
            // и после перезапроса он встаёт первым среди обычных постов.
            onPostReposted: this.reloadPosts,
        };
    },
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
        /**
         * only: ['posts'] особенно уместен здесь: профиль в шапке не меняется,
         * и пересылать его на клиент заново незачем.
         */
        reloadPosts() {
            router.reload({ only: ['posts'] });
        },
    },
};
</script>
