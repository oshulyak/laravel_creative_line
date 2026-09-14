<template>
    <Head :title="chat.title" />

    <section class="mb-6 rounded-lg bg-white p-5 shadow">
        <h1 class="text-2xl font-semibold text-gray-900">{{ chat.title }}</h1>

        <!-- Участники со ссылками на профили. Себя тоже показываем: это честный состав чата. -->
        <ul class="mt-3 flex flex-wrap gap-2">
            <li v-for="profile in chat.profiles" :key="profile.id">
                <Link
                    :href="route('client.profiles.show', profile.id)"
                    class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-600 hover:bg-gray-200"
                >
                    {{ profile.nickname }}
                </Link>
            </li>
        </ul>
    </section>

    <!-- Сообщения появятся в следующем уроке; пока только пустое состояние. -->
    <p class="rounded-lg bg-white p-6 text-sm text-gray-500">Сообщений пока нет.</p>
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link },
    props: {
        // required: true: страница без чата не имеет смысла.
        chat: {
            type: Object,
            required: true,
        },
    },
};
</script>
