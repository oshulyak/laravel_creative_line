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

        <!-- Панель действий: лайк, «Ответить», «Показать ответы». -->
        <div class="mt-2 flex items-center gap-4 text-xs">
            <LikeButton
                :url="route('client.comments.likes.toggle', comment.id)"
                :initial-liked="comment.is_liked"
                :initial-count="comment.likes_count"
                icon-class="h-4 w-4"
            />

            <!--
                canReply закрывает сразу обе кнопки. У ответа ветки быть не может
                (сервер её не создаст), и предлагать ответить на ответ тоже нельзя —
                запрос вернёт 404. Один проп держит оба ограничения.
            -->
            <button
                v-if="canReply"
                type="button"
                class="text-gray-400 hover:text-sky-700"
                @click="isReplyFormShown = !isReplyFormShown"
            >
                {{ isReplyFormShown ? 'Отмена' : 'Ответить' }}
            </button>

            <!--
                Кнопки ветки нет, пока ответов нет: «Показать ответы (0)» бессмысленно.
                Как только придёт первый ответ, repliesCount станет единицей —
                и кнопка появится сама, реактивно.
            -->
            <button
                v-if="canReply && repliesCount > 0"
                type="button"
                class="text-gray-400 hover:text-sky-700"
                @click="toggleReplies"
            >
                {{ areRepliesShown ? 'Скрыть ответы' : `Показать ответы (${repliesCount})` }}
            </button>
        </div>

        <!--
            Форма ответа — та же CommentForm, что и над списком. Отличий два:
            другой адрес и другая подпись поля.
        -->
        <CommentForm
            v-if="isReplyFormShown"
            :url="route('client.comments.replies.store', comment.id)"
            placeholder="Ваш ответ…"
            class="mt-3"
            @created="handleReplyCreated"
        />

        <div v-if="areRepliesShown" class="mt-2 border-l-2 border-gray-100 pl-4">
            <p v-if="isLoadingReplies" class="py-2 text-xs text-gray-400">Загружаю ответы…</p>

            <!--
                Компонент вызывает сам себя. Это законный приём: ответ — такой же
                комментарий, и рисовать его нужно так же. Ограничитель — :can-reply="false":
                внутри ответа обе кнопки исчезнут, и рекурсия закончится на первом уровне.
            -->
            <ItemComment
                v-for="reply in replies"
                :key="reply.id"
                :comment="reply"
                :can-reply="false"
            />
        </div>
    </article>
</template>

<script>
import axios from 'axios';
import CommentForm from '@/Components/Comment/CommentForm.vue';
import LikeButton from '@/Components/LikeButton.vue';

export default {
    // Для рекурсии name обязателен: себя компонент находит по имени, импортировать
    // сам себя он не может. Строка ниже — не документация, а рабочий код.
    name: 'ItemComment',
    components: { CommentForm, LikeButton },
    props: {
        comment: {
            type: Object,
            required: true,
        },
        // Единственная разница между комментарием и ответом на клиенте.
        // Значение по умолчанию true — список ничего передавать не обязан.
        canReply: {
            type: Boolean,
            default: true,
        },
    },
    data() {
        return {
            isReplyFormShown: false,
            areRepliesShown: false,
            // «Ветку уже привозили» — чтобы второе открытие не било в сервер.
            areRepliesLoaded: false,
            isLoadingReplies: false,
            replies: [],
            // Локальная копия счётчика: он будет меняться при отправке ответа,
            // а пропсы менять нельзя — они принадлежат родителю.
            //
            // ?? 0 — не украшение: ключа replies_count нет в ответе на создание
            // комментария (withCount() там не звали), и у только что отправленного
            // комментария приедет undefined.
            repliesCount: this.comment.replies_count ?? 0,
        };
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
    methods: {
        /**
         * Ветка грузится лениво — при первом развороте, а не вместе со списком.
         *
         * Это главная причина, по которой ответы вообще сделаны отдельным запросом:
         * десять комментариев со всеми ветками — это десятки лишних записей в JSON,
         * которые в большинстве случаев никто не откроет.
         */
        toggleReplies() {
            this.areRepliesShown = !this.areRepliesShown;

            if (this.areRepliesShown && !this.areRepliesLoaded) {
                this.loadReplies();
            }
        },
        loadReplies() {
            if (this.isLoadingReplies) {
                return;
            }

            this.isLoadingReplies = true;

            axios
                .get(route('client.comments.replies.index', this.comment.id))
                .then((res) => {
                    // Пагинации нет, поэтому берём res.data.data целиком —
                    // обёртка data осталась от коллекции ресурса.
                    this.replies = res.data.data;
                    // Синхронизируем счётчик с тем, что реально приехало: пока
                    // страница висела открытой, ответов могло стать больше.
                    this.repliesCount = this.replies.length;
                    this.areRepliesLoaded = true;
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isLoadingReplies = false;
                });
        },
        /**
         * Событие created от формы ответа.
         *
         * Две ситуации, и их важно различать. Если ветка уже загружена — просто
         * дописываем ответ в конец (порядок хронологический, поэтому push,
         * а не unshift, как в списке). Если нет — грузим её целиком: свежий
         * ответ приедет вместе с остальными, и дубля не будет.
         */
        handleReplyCreated(reply) {
            this.isReplyFormShown = false;
            this.areRepliesShown = true;

            if (this.areRepliesLoaded) {
                this.replies.push(reply);
                this.repliesCount += 1;

                return;
            }

            this.loadReplies();
        },
    },
};
</script>
