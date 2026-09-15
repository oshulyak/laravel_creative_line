<template>
    <Head :title="group.title" />

    <section class="mb-6 flex items-start justify-between gap-4 rounded-lg bg-white p-5 shadow">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold text-gray-900">{{ group.title }}</h1>

            <!-- whitespace-pre-line сохраняет переносы строк из описания. -->
            <p v-if="group.description" class="mt-2 whitespace-pre-line text-sm text-gray-600">
                {{ group.description }}
            </p>
        </div>

        <!-- После вступления форма новой темы появляется без перезагрузки страницы. -->
        <SubscribeGroupButton :group="group" @toggled="isSubscribed = $event.is_subscribed" />
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-medium text-gray-900">Темы</h2>

        <!--
            Форму видят только участники. Это подсказка, а не защита:
            проверку делает Theme\StoreRequest::authorize().
        -->
        <form v-if="isSubscribed" class="mt-4" @submit.prevent="storeTheme">
            <div class="flex gap-2">
                <input
                    v-model="title"
                    type="text"
                    maxlength="255"
                    placeholder="Название новой темы"
                    class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                />

                <button
                    type="submit"
                    :disabled="isSending || !title.trim()"
                    class="shrink-0 rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
                >
                    {{ isSending ? 'Создаю…' : 'Создать тему' }}
                </button>
            </div>

            <p v-if="errors.title" class="mt-1 text-sm text-red-600">{{ errors.title }}</p>
        </form>

        <p v-else class="mt-4 text-sm text-gray-500">
            Создавать темы и писать в них могут участники группы.
        </p>

        <p v-if="!themes.length" class="mt-4 text-sm text-gray-500">Тем пока нет.</p>

        <ul v-else class="mt-4 divide-y divide-gray-100">
            <li v-for="theme in themes" :key="theme.id">
                <!-- Ссылка на всю строку: по ней легче попасть, чем по короткому заголовку. -->
                <Link
                    :href="route('client.themes.show', theme.id)"
                    class="flex items-center justify-between gap-4 rounded-lg px-2 py-3 hover:bg-gray-50"
                >
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium text-gray-900">{{ theme.title }}</span>
                        <span class="block text-xs text-gray-500">{{ theme.author?.nickname ?? 'Аноним' }}</span>
                    </span>

                    <span class="shrink-0 text-xs text-gray-500">Сообщений: {{ theme.messages_count }}</span>
                </Link>
            </li>
        </ul>
    </section>
</template>

<script>
import { Head, Link, router } from '@inertiajs/vue3';
import SubscribeGroupButton from '@/Components/Group/SubscribeGroupButton.vue';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link, SubscribeGroupButton },
    props: {
        // Группа с is_subscribed и subscribers_count — ключ group из GroupMapper::show().
        group: {
            type: Object,
            required: true,
        },
        // Ключ themes из GroupMapper::show(). Пустой список приедет как [].
        themes: {
            type: Array,
            required: true,
        },
    },
    data() {
        return {
            // Локальная копия: меняется, когда пользователь вступает или выходит.
            // От неё зависит форма, поэтому v-if смотрит сюда, а не в проп.
            isSubscribed: Boolean(this.group.is_subscribed),
            title: '',
            // Ошибки валидации из onError: { title: '...' }.
            errors: {},
            isSending: false,
        };
    },
    methods: {
        /**
         * router.post(), а не axios: после создания нужно перейти на страницу
         * новой темы. Сервер ответит редиректом, Inertia откроет её сама.
         */
        storeTheme() {
            this.isSending = true;
            this.errors = {};

            router.post(
                route('client.groups.themes.store', this.group.id),
                { title: this.title },
                {
                    onError: (errors) => {
                        this.errors = errors;
                    },
                    onFinish: () => {
                        this.isSending = false;
                    },
                },
            );
        },
    },
};
</script>
