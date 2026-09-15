<template>
    <Head title="Группы" />

    <header class="mb-4 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold text-gray-900">Группы</h1>

        <!-- Кнопка и модальное окно живут в своём компоненте, как у CreateGroupChat. -->
        <CreateGroup />
    </header>

    <p v-if="!groups.length" class="rounded-lg bg-white p-6 text-sm text-gray-500">
        Групп пока нет. Создайте первую.
    </p>

    <ul v-else class="flex flex-col gap-3">
        <li
            v-for="group in groups"
            :key="group.id"
            class="flex items-start justify-between gap-4 rounded-lg bg-white p-5 shadow"
        >
            <!-- min-w-0: без него внутри flex длинное название не обрежется truncate. -->
            <div class="min-w-0">
                <Link
                    :href="route('client.groups.show', group.id)"
                    class="block truncate font-medium text-gray-900 hover:text-sky-700"
                >
                    {{ group.title }}
                </Link>

                <!-- В каталоге хватит начала описания, целиком оно на странице группы. -->
                <p v-if="group.description" class="mt-1 line-clamp-2 text-sm text-gray-500">
                    {{ group.description }}
                </p>
            </div>

            <!-- Кнопка ведёт своё состояние сама: каталог после вступления перезагружать не нужно. -->
            <SubscribeGroupButton :group="group" />
        </li>
    </ul>
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';
import CreateGroup from '@/Components/Group/CreateGroup.vue';
import SubscribeGroupButton from '@/Components/Group/SubscribeGroupButton.vue';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Index',
    layout: ClientLayout,
    components: { Head, Link, CreateGroup, SubscribeGroupButton },
    props: {
        // Ключ groups из GroupMapper::index(). Пустой список приедет как [].
        groups: {
            type: Array,
            required: true,
        },
    },
};
</script>
