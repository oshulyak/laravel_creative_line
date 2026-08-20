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

        <textarea
            v-model="post.content"
            placeholder="content"
            class="mb-4 w-full border border-gray-200 p-4"
        ></textarea>

        <!--
            :value с двоеточием, а не value: без него в модель уедет строка
            ("null" вместо null, "3" вместо 3) и правило integer на бэке заругается.
            disabled делает первый пункт подписью-заглушкой: виден, но выбрать нельзя.
        -->
        <select
            v-model="post.category_id"
            class="mb-4 w-full border border-gray-200 p-4"
        >
            <option :value="null" disabled>Категория</option>
            <option
                v-for="category in categories"
                :key="category.id"
                :value="category.id"
            >
                {{ category.title }}
            </option>
        </select>

        <!--
            v-model на input[type=file] не работает: браузер запрещает программно
            подставлять файл в поле, поэтому файлы забираем в обработчике @change.
            ref нужен, чтобы очистить поле после успешной отправки — само оно не сбросится.
        -->
        <input
            ref="imagesInput"
            type="file"
            multiple
            accept="image/*"
            class="mb-4 w-full border border-gray-200 p-4"
            @change="handleImages"
        />

        <!--
            Теги вводятся одной строкой через запятую и уходят на бэк как есть:
            разбор — правило приложения, а не формы. Клиентов может быть несколько
            (форма, API, импорт), и данным от клиента всё равно нельзя доверять.
        -->
        <textarea
            v-model="tags"
            placeholder="теги через запятую"
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
     * Справочник категорий приезжает пропсом из PostController::create():
     * список вариантов живёт в БД, а не в шаблоне.
     */
    props: {
        categories: {
            type: Array,
            default: () => [],
        },
    },
    /**
     * Состояние компонента. Всё, что здесь возвращается, реактивно:
     * v-model связывает поле формы и свойство объекта в обе стороны.
     *
     * Поля перечислены явно, а не через `post: {}`: пустой объект не содержал бы
     * ключей нетронутых полей, и на бэк ушёл бы запрос без них.
     *
     * images и tags лежат отдельно от post: в post собраны только колонки таблицы posts,
     * он целиком уходит в Post::create(). Изображения и теги — это связи.
     *
     * published_at здесь нет: дату публикации проставляет сервер (см. StoreRequest),
     * клиент на неё не влияет.
     */
    data() {
        return {
            post: {
                title: '',
                content: '',
                category_id: null,
            },
            images: [],
            tags: '',
        };
    },
    methods: {
        handleImages(event) {
            // event.target.files — FileList, а не массив: Array.from даёт настоящий массив.
            this.images = Array.from(event.target.files);
        },
        storePost() {
            // Файл нельзя отправить обычным объектом: axios сериализует его в JSON,
            // а File в JSON не превращается. Нужен FormData — браузер закодирует
            // его как multipart/form-data. Content-Type руками не ставим:
            // axios подставит его сам вместе с boundary.
            const formData = new FormData();

            Object.entries(this.post).forEach(([key, value]) => {
                // FormData умеет передавать только строки и файлы, поэтому null
                // превратился бы в "null". Пустую строку middleware
                // ConvertEmptyStringsToNull вернёт обратно в null уже на бэке.
                formData.append(key, value ?? '');
            });

            // images[] — соглашение PHP: повторяющиеся ключи со скобками
            // собираются в массив, который проверит правило images.*.
            this.images.forEach((image) => {
                formData.append('images[]', image);
            });

            formData.append('tags', this.tags);

            // Приложение — монолит, поэтому URL берём у Ziggy по имени роута.
            axios
                .post(route('admin.posts.store'), formData)
                .then((res) => {
                    console.log(res.data);

                    // Очищаем форму только после подтверждения сервера: при ошибке
                    // введённые данные должны остаться, чтобы их можно было исправить.
                    this.post = {
                        title: '',
                        content: '',
                        category_id: null,
                    };
                    this.images = [];
                    this.tags = '';
                    this.$refs.imagesInput.value = '';
                })
                .catch((e) => {
                    // 4xx / 5xx: тело ответа лежит в e.response.
                    console.log(e.response);
                });
        },
    },
};
</script>
