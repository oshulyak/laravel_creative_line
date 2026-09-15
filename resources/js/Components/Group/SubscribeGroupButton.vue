<template>
    <!-- Корень один: счётчик и кнопка — два узла. -->
    <div class="flex shrink-0 items-center gap-3">
        <span class="text-sm text-gray-500">Участников: {{ count }}</span>

        <button
            type="button"
            :disabled="isPending"
            class="rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50"
            :class="
                isSubscribed
                    ? 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                    : 'border-sky-700 bg-sky-700 text-white hover:bg-sky-800'
            "
            @click="toggle"
        >
            {{ isSubscribed ? 'Выйти' : 'Вступить' }}
        </button>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'SubscribeGroupButton',
    props: {
        // Группа целиком, а не готовый url, как у LikeButton: вступают только
        // в группу, обобщать не для кого. Нужны id, is_subscribed и subscribers_count.
        group: {
            type: Object,
            required: true,
        },
    },
    // Странице группы важно знать, что пользователь вступил: от этого
    // зависит, показывать ли форму новой темы.
    emits: ['toggled'],
    data() {
        return {
            // Локальные копии: кнопка меняет состояние после ответа сервера,
            // а писать в проп нельзя.
            //
            // watch на проп, как у LikeButton, не нужен: страницы групп
            // не делают частичную перезагрузку, и проп после открытия не меняется.
            isSubscribed: Boolean(this.group.is_subscribed),
            count: this.group.subscribers_count ?? 0,
            isPending: false,
        };
    },
    methods: {
        toggle() {
            this.isPending = true;

            axios
                .post(route('client.groups.subscribers.toggle', this.group.id))
                .then((res) => {
                    // Оба значения берём из ответа: сервер — единственный источник правды.
                    this.isSubscribed = res.data.is_subscribed;
                    this.count = res.data.subscribers_count;

                    this.$emit('toggled', res.data);
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isPending = false;
                });
        },
    },
};
</script>
