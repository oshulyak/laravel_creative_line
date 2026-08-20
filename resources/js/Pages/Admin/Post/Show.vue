<template>
    <Head :title="post.title" />

    <Link
        :href="route('admin.posts.index')"
        class="mb-4 inline-block bg-sky-700 px-3 py-2 text-xs text-white hover:bg-sky-800"
    >
        Посты
    </Link>

    <article class="rounded-lg bg-white p-6 shadow">
        <h3 class="mb-2 text-2xl font-semibold text-gray-900">{{ post.title }}</h3>

        <p class="mb-4 text-sm text-gray-500">
            {{ post.category?.title ?? 'Без категории' }} · автор #{{ post.author_id }}
        </p>

        <!--
            post.images?.length, а не post.images.length: ключ приходит из whenLoaded()
            и при незагруженной связи его в props вообще нет — обращение к .length
            у undefined уронит рендер страницы.
        -->
        <div v-if="post.images?.length" class="mb-4 grid grid-cols-3 gap-2">
            <img
                v-for="image in post.images"
                :key="image.id"
                :src="image.url"
                :alt="post.title"
                class="h-40 w-full rounded object-cover"
            />
        </div>

        <!-- whitespace-pre-line сохраняет переносы строк, набранные в textarea. -->
        <p class="whitespace-pre-line text-gray-700">{{ post.content }}</p>

        <ul v-if="post.tags?.length" class="mt-4 flex flex-wrap gap-2">
            <li
                v-for="tag in post.tags"
                :key="tag.id"
                class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600"
            >
                #{{ tag.title }}
            </li>
        </ul>
    </article>
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
    name: 'Show',
    layout: AdminLayout,
    components: { Head, Link },
    props: {
        // required: true, а не default: страница без поста не имеет смысла,
        // и Vue предупредит в консоли, если props не приедет.
        post: {
            type: Object,
            required: true,
        },
    },
};
</script>
