<template>
    <Head :title="post.title" />

    <Link
        :href="route('client.feed.index')"
        class="mb-4 inline-block text-sm text-sky-700 hover:underline"
    >
        ← В ленту
    </Link>

    <article class="rounded-lg bg-white p-6 shadow">
        <h1 class="mb-2 text-2xl font-semibold text-gray-900">{{ post.title }}</h1>

        <p class="mb-4 text-sm text-gray-500">
            {{ post.author?.nickname ?? 'Аноним' }} ·
            {{ post.category?.title ?? 'Без категории' }}
        </p>

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

        <!--
            Вся логика лайка уехала в LikeButton, страница только сообщает,
            куда стучаться и с чего начать. icon-class крупнее, чем в ленте:
            размер задаёт родитель.

            class="mt-6 text-sm" на компоненте — fallthrough-атрибут: Vue сам
            перенесёт его на корневой <button> и ДОБАВИТ к объявленным внутри
            классам, а не заменит их.
        -->
        <LikeButton
            :url="route('client.posts.likes.toggle', post.id)"
            :initial-liked="post.is_liked"
            :initial-count="post.likes_count"
            icon-class="h-6 w-6"
            class="mt-6 text-sm"
        />
    </article>

    <!--
        Комментарии не приходят пропсом страницы: за ними список сходит сам,
        порциями и уже после того, как пост показан.
    -->
    <CommentList :post-id="post.id" />
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';
import LikeButton from '@/Components/LikeButton.vue';
import CommentList from '@/Components/Comment/CommentList.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link, LikeButton, CommentList },
    props: {
        // required: true, а не default: страница без поста не имеет смысла.
        post: {
            type: Object,
            required: true,
        },
    },
};
</script>
