<template>
    <!--
        Сообщение форума, а не пузырь чата: все сообщения выровнены одинаково,
        автор и дата — в шапке. Чья это реплика, видно по нику, а не по стороне экрана.

        first:border-t-0 — у первого сообщения нет разделителя сверху:
        над ним и так граница блока.
    -->
    <article class="flex gap-3 border-t border-gray-100 py-4 first:border-t-0 first:pt-0">
        <!-- Заглушка аватара с первой буквой ника: так реплики легче различать глазами. -->
        <div
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sm font-semibold uppercase text-sky-800"
        >
            {{ initial }}
        </div>

        <!-- min-w-0: без него длинное слово растянет flex-строку за край блока. -->
        <div class="min-w-0 flex-1">
            <header class="flex items-baseline gap-2">
                <span class="text-sm font-medium text-gray-900">{{ nickname }}</span>
                <span class="text-xs text-gray-400">{{ createdAt }}</span>

                <!-- Номер сообщения в теме, как на форумах: на него удобно сослаться в ответе. -->
                <span class="ml-auto text-xs text-gray-400">#{{ number }}</span>
            </header>

            <!--
                whitespace-pre-line сохраняет переносы строк из textarea,
                break-words переносит слово, которое не помещается в строку целиком.
            -->
            <p class="mt-1 whitespace-pre-line break-words text-sm text-gray-700">{{ message.content }}</p>
        </div>
    </article>
</template>

<script>
export default {
    name: 'ItemThemeMessage',
    props: {
        // Сообщение в том виде, в каком его отдаёт ThemeMessageResource.
        message: {
            type: Object,
            required: true,
        },
        // Порядковый номер в теме. Считает страница по положению в ленте:
        // в самом сообщении номера нет, это не колонка базы.
        number: {
            type: Number,
            required: true,
        },
    },
    computed: {
        // ?. и ?? — страховка, как в ItemComment: author приходит из whenLoaded().
        nickname() {
            return this.message.author?.nickname ?? 'Аноним';
        },
        initial() {
            return this.nickname.charAt(0);
        },
        /**
         * Дата в местном времени: «15 сентября 2026 г. в 12:30».
         *
         * dateStyle: 'long', как в ItemComment: тема живёт долго, и дата
         * с названием месяца читается лучше, чем 15.09.2026.
         */
        createdAt() {
            return new Date(this.message.created_at).toLocaleString('ru-RU', {
                dateStyle: 'long',
                timeStyle: 'short',
            });
        },
    },
};
</script>
