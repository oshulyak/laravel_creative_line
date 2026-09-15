<template>
    <!--
        Свои сообщения прижаты вправо, чужие — влево. items-end / items-start
        выравнивают сразу и пузырь, и подпись под ним.
    -->
    <div class="flex flex-col" :class="isMine ? 'items-end' : 'items-start'">
        <!-- whitespace-pre-line сохраняет переносы строк, набранные в textarea. -->
        <p class="max-w-[75%] whitespace-pre-line rounded-2xl px-4 py-2 text-sm" :class="bubbleClass">{{ message.content }}</p>

        <p class="mt-1 px-1 text-xs text-gray-400">
            {{ message.author?.nickname ?? 'Аноним' }} · {{ createdAt }}
        </p>
    </div>
</template>

<script>
export default {
    name: 'ItemMessage',
    props: {
        // Сообщение в том виде, в каком его отдаёт MessageResource.
        message: {
            type: Object,
            required: true,
        },
    },
    computed: {
        /**
         * Моё ли это сообщение.
         *
         * Сравниваем с id ПРОФИЛЯ, а не пользователя: messages.author_id
         * ссылается на profiles. auth.user.id — другое число, и с ним
         * все сообщения оказались бы «чужими».
         *
         * $page — общие пропсы Inertia, доступные в любом компоненте.
         * Профиля может не быть, отсюда ?. — тогда своих сообщений просто нет.
         */
        isMine() {
            return this.message.author_id === this.$page.props.auth.user.profile?.id;
        },
        /**
         * Оформление пузыря.
         *
         * computed зависит от другого computed: когда изменится isMine,
         * Vue пересчитает и его. Классы вынесены сюда, а не записаны
         * тернарником в шаблоне: вариантов оформления два, в каждом по
         * три класса, и в атрибуте они читались бы плохо.
         *
         * rounded-br-sm / rounded-bl-sm — «хвостик» пузыря со стороны автора.
         */
        bubbleClass() {
            return this.isMine
                ? 'rounded-br-sm bg-sky-700 text-white'
                : 'rounded-bl-sm bg-gray-100 text-gray-800';
        },
        /**
         * Дата в местном времени: «15.09.2026, 12:30».
         *
         * С сервера created_at приезжает в UTC, часовой пояс пользователя
         * знает только браузер — так же форматируется дата в ItemComment.
         */
        createdAt() {
            return new Date(this.message.created_at).toLocaleString('ru-RU', {
                dateStyle: 'short',
                timeStyle: 'short',
            });
        },
    },
};
</script>
