<template>
    <Head title="Чаты" />

    <header class="mb-4 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold text-gray-900">Чаты</h1>

        <!-- Кнопка и модальное окно живут в своём компоненте, как у RepostButton. -->
        <CreateGroupChat />
    </header>

    <p v-if="!chats.length" class="rounded-lg bg-white p-6 text-sm text-gray-500">
        Чатов пока нет.
    </p>

    <ul v-else class="divide-y divide-gray-100 rounded-lg bg-white shadow">
        <li v-for="chat in chats" :key="chat.id">
            <!-- Ссылка на всю строку: по ней легче попасть, чем по короткому заголовку. -->
            <Link
                :href="route('client.chats.show', chat.id)"
                class="flex items-center justify-between gap-4 px-5 py-3 hover:bg-gray-50"
            >
                <!--
                    Заголовок уже собран сервером: у группы — название,
                    у диалога — ник собеседника.
                -->
                <span class="truncate text-sm font-medium text-gray-900">{{ chat.title }}</span>

                <span class="shrink-0 text-xs text-gray-500">
                    Участников: {{ chat.profiles.length }}
                </span>
            </Link>
        </li>
    </ul>
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';
import CreateGroupChat from '@/Components/Chat/CreateGroupChat.vue';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Index',
    layout: ClientLayout,
    components: { Head, Link, CreateGroupChat },
    props: {
        // Ключ chats из ChatMapper::index(). Пустой список приедет как [].
        //
        // После создания чата список обновлять не нужно: пользователь сразу
        // уходит на страницу нового чата, а при возврате сюда страница
        // загрузится заново.
        chats: {
            type: Array,
            required: true,
        },
    },
};
</script>
