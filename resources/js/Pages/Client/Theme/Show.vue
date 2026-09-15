<template>
    <Head :title="theme.title" />

    <section class="mb-6 rounded-lg bg-white p-5 shadow">
        <Link :href="route('client.groups.show', theme.group.id)" class="text-sm text-sky-700 hover:underline">
            ← {{ theme.group.title }}
        </Link>

        <h1 class="mt-2 text-2xl font-semibold text-gray-900">{{ theme.title }}</h1>

        <p class="mt-1 text-sm text-gray-500">Автор: {{ theme.author?.nickname ?? 'Аноним' }}</p>
    </section>

    <section class="rounded-lg bg-white p-5 shadow">
        <!--
            Лента в стиле форума, а не чата: сообщения одно под другим,
            разделены линией. Номер — положение в ленте: она идёт от старых
            к новым, и новое сообщение дописывается в конец, так что номера
            уже показанных сообщений не сдвигаются.
        -->
        <div v-if="themeMessages.length">
            <ItemThemeMessage
                v-for="(message, index) in themeMessages"
                :key="message.id"
                :message="message"
                :number="index + 1"
            />
        </div>

        <p v-else class="text-sm text-gray-500">Сообщений пока нет.</p>

        <!-- Форма — только участникам. Защита — в ThemeMessage\StoreRequest::authorize(). -->
        <form
            v-if="theme.group.is_subscribed"
            class="mt-6 border-t border-gray-100 pt-4"
            @submit.prevent="storeMessage"
        >
            <textarea
                v-model="content"
                rows="2"
                maxlength="2000"
                placeholder="Сообщение…"
                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                :disabled="isSending"
            />

            <!-- Ошибку показываем ту, что вернул сервер: правило живёт в StoreRequest. -->
            <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>

            <div class="mt-2 flex justify-end">
                <button
                    type="submit"
                    :disabled="isSending || !content.trim()"
                    class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
                >
                    {{ isSending ? 'Отправляю…' : 'Отправить' }}
                </button>
            </div>
        </form>

        <p v-else class="mt-6 border-t border-gray-100 pt-4 text-sm text-gray-500">
            Писать в тему могут участники группы.
            <Link :href="route('client.groups.show', theme.group.id)" class="text-sky-700 hover:underline">
                Перейти в группу
            </Link>
        </p>
    </section>
</template>

<script>
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import ItemThemeMessage from '@/Components/ThemeMessage/ItemThemeMessage.vue';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link, ItemThemeMessage },
    props: {
        // Тема с автором и группой — ключ theme из ThemeMapper::show().
        theme: {
            type: Object,
            required: true,
        },
        // Сообщения на момент открытия страницы. Пустая тема приедет с [].
        messages: {
            type: Array,
            required: true,
        },
    },
    data() {
        return {
            // Локальная копия пропса: новые сообщения дописываются в ленту,
            // а в проп писать нельзя. Как chatMessages в Chat/Show.
            themeMessages: [...this.messages],
            content: '',
            error: '',
            isSending: false,
        };
    },
    methods: {
        /**
         * Отправка — как на странице чата, но без веб-сокетов: своё сообщение
         * встаёт в ленту из ответа, чужие видны после перезагрузки.
         */
        storeMessage() {
            this.isSending = true;
            // Старую ошибку убираем до запроса, иначе она провисит до ответа.
            this.error = '';

            axios
                .post(route('client.themes.messages.store', this.theme.id), {
                    content: this.content,
                })
                .then((res) => {
                    // push: лента идёт от старых к новым, свежее сообщение — в конец.
                    this.themeMessages.push(res.data);
                    // Поле чистим только после успеха: при ошибке текст должен остаться.
                    this.content = '';
                })
                .catch((e) => {
                    // 422 приходит как { errors: { content: [...] } }. На 403 и 500
                    // такой структуры нет — показываем общий текст.
                    this.error = e.response?.data?.errors?.content?.[0]
                        ?? 'Не удалось отправить сообщение.';
                })
                .finally(() => {
                    this.isSending = false;
                });
        },
    },
};
</script>
