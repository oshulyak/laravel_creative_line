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

    <section class="rounded-lg bg-white p-5 shadow">
        <!--
            Лента. Каждое сообщение — отдельный компонент: ему нужны свои
            computed, а computed не принимает аргументов.
        -->
        <div v-if="chatMessages.length" class="flex flex-col gap-3">
            <ItemMessage
                v-for="message in chatMessages"
                :key="message.id"
                :message="message"
            />
        </div>

        <p v-else class="text-sm text-gray-500">Сообщений пока нет.</p>

        <!--
            form + @submit.prevent: браузер понимает, что это форма, а .prevent
            отменяет штатную перезагрузку страницы — отправляем через axios.
        -->
        <form class="mt-6 border-t border-gray-100 pt-4" @submit.prevent="storeMessage">
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
    </section>
</template>

<script>
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import ItemMessage from '@/Components/Message/ItemMessage.vue';
import ClientLayout from '@/Layouts/ClientLayout.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link, ItemMessage },
    props: {
        // required: true: страница без чата не имеет смысла.
        chat: {
            type: Object,
            required: true,
        },
        // Сообщения на момент открытия страницы — ключ messages из ChatMapper.
        // required: true — маппер отдаёт его всегда, пустой чат приедет с [].
        messages: {
            type: Array,
            required: true,
        },
    },
    data() {
        return {
            // Локальная копия пропса. Новые сообщения дописываются в ленту,
            // а проп принадлежит серверу: писать в него нельзя, поток данных
            // односторонний. Тот же приём, что postData в ItemPost.
            //
            // Имя другое, потому что проп и поле data с одинаковым именем
            // в одном компоненте не уживутся.
            chatMessages: [...this.messages],
            content: '',
            error: '',
            isSending: false,
        };
    },
    methods: {
        storeMessage() {
            this.isSending = true;
            // Старую ошибку убираем до запроса, иначе она провисит до ответа.
            this.error = '';

            axios
                .post(route('client.chats.messages.store', this.chat.id), {
                    content: this.content,
                })
                .then((res) => {
                    // push, а не unshift: лента идёт от старых к новым,
                    // свежее сообщение встаёт в конец.
                    //
                    // В res.data — сообщение ровно в той форме, в какой его
                    // отдаёт маппер: один MessageResource на оба случая.
                    this.chatMessages.push(res.data);
                    // Поле чистим только после успеха: при ошибке текст
                    // должен остаться.
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
