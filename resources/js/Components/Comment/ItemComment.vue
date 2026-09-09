<template>
    <article class="border-t border-gray-100 py-3">
        <p class="text-xs text-gray-400">
            <!--
                ?. и ?? — та же страховка, что в карточке поста: author приходит
                из whenLoaded(), и при незагруженной связи ключа в пропсах нет.
            -->
            {{ comment.author?.nickname ?? 'Аноним' }} · {{ publishedAt }}
        </p>

        <!-- whitespace-pre-line сохраняет переносы строк, набранные в textarea. -->
        <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ comment.content }}</p>

        <LikeButton
            :url="route('client.comments.likes.toggle', comment.id)"
            :initial-liked="comment.is_liked"
            :initial-count="comment.likes_count"
            icon-class="h-4 w-4"
            class="mt-2 text-xs"
        />
    </article>
</template>

<script>
import LikeButton from '@/Components/LikeButton.vue';

export default {
    name: 'ItemComment',
    components: { LikeButton },
    props: {
        comment: {
            type: Object,
            required: true,
        },
    },
    computed: {
        /**
         * Дата в человеческом виде.
         *
         * computed, а не method: значение зависит только от пропса, и Vue закеширует
         * результат до его изменения. Метод пересчитывался бы на каждый рендер списка.
         *
         * Форматируем на клиенте, а не на сервере: браузер знает часовой пояс
         * пользователя, PHP — нет. С сервера дата приезжает в UTC и в ISO-8601
         * (это дал каст в модели), и toLocaleString переводит её в местное время.
         */
        publishedAt() {
            if (!this.comment.published_at) {
                return '';
            }

            return new Date(this.comment.published_at).toLocaleString('ru-RU', {
                dateStyle: 'long',
                timeStyle: 'short',
            });
        },
    },
};
</script>
