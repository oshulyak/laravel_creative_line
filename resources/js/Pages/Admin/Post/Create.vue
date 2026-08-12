<template>
    <Head title="Создание поста" />

    <Link
        :href="route('admin.posts.index')"
        class="mb-4 inline-block bg-sky-700 px-3 py-2 text-xs text-white hover:bg-sky-800"
    >
        Посты
    </Link>

    <div class="bg-white p-4">
        <input
            v-model="post.title"
            placeholder="title"
            class="mb-4 w-full border border-gray-200 p-4"
        />

        <input
            v-model="post.published_at"
            type="datetime-local"
            class="mb-4 w-full border border-gray-200 p-4"
        />

        <textarea
            v-model="post.content"
            placeholder="content"
            class="mb-4 w-full border border-gray-200 p-4"
        ></textarea>

        <a
            href="#"
            class="inline-block bg-teal-700 px-3 py-2 text-xs text-white hover:bg-teal-800"
            @click.prevent="storePost"
        >
            Создать
        </a>
    </div>
</template>

<script>
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
    name: 'Create',
    layout: AdminLayout,
    components: { Head, Link },
    /**
     * Состояние компонента. Всё, что здесь возвращается, реактивно:
     * v-model связывает поле формы и свойство объекта в обе стороны.
     *
     * Поля перечислены явно, а не через `post: {}`: пустой объект не содержал бы
     * ключей нетронутых полей, и на бэк ушёл бы запрос без них.
     */
    data() {
        return {
            post: {
                title: '',
                content: '',
                published_at: '',
            },
        };
    },
    methods: {
        storePost() {
            // Приложение — монолит, поэтому URL берём у Ziggy по имени роута.
            axios
                .post(route('admin.posts.store'), this.post)
                .then((res) => {
                    console.log(res.data);

                    // Очищаем форму только после подтверждения сервера: при ошибке
                    // введённые данные должны остаться, чтобы их можно было исправить.
                    this.post = {
                        title: '',
                        content: '',
                        published_at: '',
                    };
                })
                .catch((e) => {
                    // 4xx / 5xx: тело ответа лежит в e.response.
                    console.log(e.response);
                });
        },
    },
};
</script>
