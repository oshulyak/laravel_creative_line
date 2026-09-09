<template>
    <!--
        form + @submit.prevent, а не просто кнопка: так работает отправка
        по Ctrl+Enter и по Enter в других полях, а браузер понимает, что это форма.
        .prevent отменяет штатную перезагрузку страницы — отправляем через axios.
    -->
    <form class="mb-4" @submit.prevent="submit">
        <textarea
            v-model="content"
            rows="3"
            maxlength="2000"
            placeholder="Написать комментарий…"
            class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
            :disabled="isSending"
        />

        <!--
            Ошибку показываем ту, что вернул сервер: клиентская проверка «поле
            не пустое» — это удобство, а не правило. Правило живёт в StoreRequest,
            и оно одно на все способы отправки.
        -->
        <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>

        <div class="mt-2 flex items-center justify-between">
            <span class="text-xs text-gray-400">{{ content.length }} / 2000</span>

            <button
                type="submit"
                :disabled="isSending || !content.trim()"
                class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
            >
                {{ isSending ? 'Отправляю…' : 'Отправить' }}
            </button>
        </div>
    </form>
</template>

<script>
import axios from 'axios';

export default {
    name: 'CommentForm',
    props: {
        postId: {
            type: Number,
            required: true,
        },
    },
    // Компонент сообщает, ЧТО произошло, и не решает, что с этим делать:
    // вставить комментарий в список — забота списка (тот же принцип, что
    // у DeletePost в 24-м уроке).
    emits: ['created'],
    data() {
        return {
            content: '',
            error: '',
            isSending: false,
        };
    },
    methods: {
        submit() {
            this.isSending = true;
            // Старую ошибку убираем до запроса, иначе она провисит до ответа
            // и будет выглядеть как реакция на новую отправку.
            this.error = '';

            axios
                .post(route('client.posts.comments.store', this.postId), {
                    content: this.content,
                })
                .then((res) => {
                    // Поле чистим только после успеха: при ошибке текст должен
                    // остаться, иначе пользователь потеряет написанное.
                    this.content = '';
                    // В res.data приехал готовый комментарий с автором — ровно
                    // в том виде, в каком его отдаёт список.
                    this.$emit('created', res.data);
                })
                .catch((e) => {
                    // 422 от валидации приходит в форме { message, errors: { content: [...] } }.
                    // Берём первое сообщение по полю; если структура другая (500, обрыв
                    // сети) — показываем общий текст, а не «undefined».
                    this.error = e.response?.data?.errors?.content?.[0]
                        ?? 'Не удалось отправить комментарий.';
                })
                .finally(() => {
                    this.isSending = false;
                });
        },
    },
};
</script>
