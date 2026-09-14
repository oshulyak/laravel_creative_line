<template>
    <Head :title="profile.nickname" />

    <section class="mb-6 flex items-start justify-between gap-4 rounded-lg bg-white p-5 shadow">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ profile.nickname }}</h1>

            <p class="text-sm text-gray-500">{{ fullName }}</p>

            <p class="mt-2 text-sm text-gray-500">Публикаций: {{ posts.meta.total }}</p>
        </div>

        <!--
            Обёртка появилась, потому что кнопок стало две: shrink-0 переехал
            с кнопки подписки на неё.
        -->
        <div class="flex shrink-0 gap-2">
            <!--
                Link, а не axios, как у подписки: после нажатия нужно оказаться
                на странице чата. Inertia отправит POST, получит от сервера
                редирект и сама откроет страницу, на которую он ведёт.

                method="post" — маршрут принимает только POST; без атрибута
                ссылка ушла бы GET-запросом и получила 405.
                as="button" — действие, а не переход по адресу: <a> с POST
                нельзя открыть в новой вкладке, и Inertia просит рисовать <button>.
            -->
            <Link
                v-if="profile.can_message"
                :href="route('client.profiles.chats.store', profile.id)"
                method="post"
                as="button"
                class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
                Написать
            </Link>

            <!--
                v-if по флагу с сервера, а не по сравнению id на клиенте: на собственной
                странице кнопки нет вовсе. Тот же приём, что с can_delete у карточки.

                <button>, а не <a href="#" @click.prevent>: это действие, а не переход.
                Кнопка сама получает фокус с клавиатуры и умеет disabled.
            -->
            <button
                v-if="profile.can_subscribe"
                type="button"
                :disabled="isSending"
                class="rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50"
                :class="
                    isSubscribed
                        ? 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                        : 'border-sky-700 bg-sky-700 text-white hover:bg-sky-800'
                "
                @click="toggleSubscribe"
            >
                {{ isSubscribed ? 'Отписаться' : 'Подписаться' }}
            </button>
        </div>
    </section>

    <p v-if="!posts.data.length" class="rounded-lg bg-white p-6 text-sm text-gray-500">
        У этого профиля пока нет публикаций.
    </p>

    <!--
        Та же карточка, что в ленте и в «Моих публикациях». Кнопки удаления
        в ней не будет: посты чужие, и can_delete приедет ложным.

        provide() здесь нет намеренно: удалять на этой странице нечего,
        а репост уезжает в «Мои публикации» — перезапрашивать чужой профиль
        после него незачем. Счётчик под иконкой репоста кнопка обновит сама.
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
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';
import ItemPost from '@/Components/Post/ItemPost.vue';

export default {
    name: 'Show',
    layout: ClientLayout,
    components: { Head, Link, ItemPost },
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
    data() {
        return {
            // Локальная копия: кнопка меняет состояние после ответа сервера,
            // а писать в проп нельзя — поток данных односторонний.
            //
            // Boolean(): ключа is_subscribed может не быть вовсе (whenHas),
            // и в data() лучше сразу положить честное булево, а не undefined.
            isSubscribed: Boolean(this.profile.is_subscribed),
            isSending: false,
        };
    },
    computed: {
        /**
         * Имя и фамилия, если они заполнены: оба поля в profiles nullable.
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
         * Отправка через axios, а не через Inertia: меняется одна надпись,
         * перерисовывать страницу и заново тянуть список постов незачем.
         *
         * Состояние берём из ответа, а не переключаем на клиенте: сервер —
         * единственный источник правды, и при отказе (403) ничего не изменится.
         */
        toggleSubscribe() {
            this.isSending = true;

            axios
                .post(route('client.profiles.subscribers.toggle', this.profile.id))
                .then((res) => {
                    this.isSubscribed = res.data.is_subscribed;
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isSending = false;
                });
        },
    },
};
</script>
