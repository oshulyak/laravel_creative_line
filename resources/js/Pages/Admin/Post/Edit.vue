<template>
    <Head :title="`Редактирование: ${post.title}`" />

    <Link
        :href="route('admin.posts.show', post.id)"
        class="mb-4 inline-block bg-sky-700 px-3 py-2 text-xs text-white hover:bg-sky-800"
    >
        К посту
    </Link>

    <div class="bg-white p-4">
        <!--
            Общее сообщение об ошибке: один блок наверху формы, чтобы отказ заметили,
            даже если проблемное поле уехало за пределы экрана.
        -->
        <p
            v-if="message"
            class="mb-4 border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ message }}
        </p>

        <input
            v-model="form.title"
            placeholder="title"
            class="mb-1 w-full border border-gray-200 p-4"
        />
        <p v-if="error('title')" class="mb-3 text-xs text-red-600">{{ error('title') }}</p>

        <textarea
            v-model="form.content"
            placeholder="content"
            class="mb-1 w-full border border-gray-200 p-4"
        ></textarea>
        <p v-if="error('content')" class="mb-3 text-xs text-red-600">{{ error('content') }}</p>

        <select
            v-model="form.category_id"
            class="mb-1 w-full border border-gray-200 p-4"
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
        <p v-if="error('category_id')" class="mb-3 text-xs text-red-600">
            {{ error('category_id') }}
        </p>

        <!--
            Уже сохранённые картинки: крестик ничего не удаляет на сервере, а лишь
            помечает картинку на удаление — оно произойдёт при сохранении формы,
            в той же транзакции, что и остальные изменения.
        -->
        <div v-if="savedImages.length" class="mb-4 grid grid-cols-4 gap-2">
            <div v-for="image in savedImages" :key="image.id" class="relative">
                <img
                    :src="image.url"
                    :alt="post.title"
                    class="h-28 w-full rounded object-cover"
                />
                <!--
                    type="button" — привычка: внутри настоящего <form> кнопка без явного
                    типа считается submit и отправила бы форму.
                -->
                <button
                    type="button"
                    class="absolute right-1 top-1 rounded-full bg-red-600 px-2 py-0.5 text-xs text-white hover:bg-red-700"
                    @click="removeSavedImage(image)"
                >
                    ×
                </button>
            </div>
        </div>

        <input
            ref="imagesInput"
            type="file"
            multiple
            accept="image/*"
            class="mb-4 w-full border border-gray-200 p-4"
            @change="handleImages"
        />

        <textarea
            v-model="tags"
            placeholder="теги через запятую"
            class="mb-4 w-full border border-gray-200 p-4"
        ></textarea>

        <!--
            Флаг saving защищает от двойного клика: второй запрос ушёл бы с тем же
            title и упал бы на unique. Объектный синтаксис :class добавляет классы
            по условию, не затирая статический class рядом.

            Настоящая защита — ранний выход в updatePost(), а не эти классы:
            pointer-events-none гасит только мышь, у <a> нет атрибута disabled
            (он есть у button/input/select), да и CSS клиент может отключить.
        -->
        <a
            href="#"
            class="inline-block bg-teal-700 px-3 py-2 text-xs text-white hover:bg-teal-800"
            :class="{ 'pointer-events-none opacity-60': saving }"
            @click.prevent="updatePost"
        >
            {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </a>
    </div>
</template>

<script>
import axios from 'axios';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
    name: 'Edit',
    layout: AdminLayout,
    components: { Head, Link },
    props: {
        post: {
            type: Object,
            required: true,
        },
        categories: {
            type: Array,
            default: () => [],
        },
    },
    /**
     * data() выполняется после разрешения props, поэтому this.post здесь уже доступен
     * и им можно инициализировать состояние формы.
     *
     * Значения копируем, а не правим props напрямую: props принадлежат родителю
     * (страницу отдал сервер), и Vue ругается в консоль на «Avoid mutating a prop
     * directly». Плюс при неудачном сохранении исходные данные остаются нетронутыми.
     */
    data() {
        return {
            form: {
                title: this.post.title,
                content: this.post.content,
                category_id: this.post.category_id,
            },
            // Картинки, уже лежащие в БД. [...] — поверхностная копия массива: без неё
            // filter/push меняли бы данные внутри props. ?? [] страхует от whenLoaded():
            // связь не загрузили — ключа нет — undefined вместо массива.
            savedImages: [...(this.post.images ?? [])],
            // id картинок, помеченных на удаление; уедут на бэк вместе с формой.
            deletedImages: [],
            // Новые файлы из <input type="file">.
            newImages: [],
            // Обратная операция к разбору строки тегов на бэке: массив моделей → строка.
            tags: (this.post.tags ?? []).map((tag) => tag.title).join(', '),
            // Ошибки валидации по полям: { title: ['...'], content: ['...'] }.
            errors: {},
            message: '',
            // Запрос в полёте: блокирует кнопку до ответа сервера.
            saving: false,
        };
    },
    methods: {
        error(field) {
            // Laravel отдаёт массив сообщений на поле; показываем первое.
            return this.errors[field]?.[0];
        },
        removeSavedImage(image) {
            this.deletedImages.push(image.id);
            this.savedImages = this.savedImages.filter((saved) => saved.id !== image.id);
        },
        handleImages(event) {
            // Новые файлы приходят как замена, а не как добавка: files у input
            // всегда содержит только последний выбор.
            this.newImages = Array.from(event.target.files);
        },
        updatePost() {
            // Ранний выход, а не только disabled в шаблоне: он отсекает и повторный
            // клик, проскочивший до перерисовки, и вызов метода из любого другого места.
            if (this.saving) {
                return;
            }

            const formData = new FormData();

            // Подмена метода (form method spoofing): PHP наполняет $_POST и $_FILES
            // из multipart-тела только для POST-запросов, поэтому настоящий PATCH
            // с файлами пришёл бы с пустым $request->all(). Шлём POST, а Laravel
            // читает _method и подменяет метод запроса — роутинг находит Route::patch.
            formData.append('_method', 'PATCH');

            Object.entries(this.form).forEach(([key, value]) => {
                formData.append(key, value ?? '');
            });

            this.newImages.forEach((image) => {
                formData.append('images[]', image);
            });

            this.deletedImages.forEach((id) => {
                formData.append('deleted_images[]', id);
            });

            formData.append('tags', this.tags);

            // Чистим прошлые ошибки перед новой попыткой, иначе исправленное поле
            // так и останется подсвеченным.
            this.errors = {};
            this.message = '';
            this.saving = true;

            axios
                .post(route('admin.posts.update', this.post.id), formData)
                .then(() => {
                    // Пост сохранён обычным XHR, и Inertia об этом ничего не знает:
                    // адресная строка и props страницы остались прежними. router —
                    // императивный аналог компонента Link: visit() делает нормальный
                    // Inertia-переход и заодно подтягивает свежие данные поста.
                    router.visit(route('admin.posts.show', this.post.id));
                })
                .catch((error) => {
                    // Разблокируем кнопку только в ветке ошибки, а не в finally:
                    // при успехе router.visit() ещё грузит следующую страницу,
                    // и снятый флаг успел бы пустить вторую отправку формы.
                    this.saving = false;
                    this.handleError(error);
                });
        },
        handleError(error) {
            // 422 — провал валидации: FormRequest вернул { message, errors }.
            // response? обязателен: если запрос не дошёл до сервера (обрыв сети, CORS),
            // у ошибки axios поля response нет вовсе, и обработчик упал бы с TypeError.
            if (error.response?.status === 422) {
                this.errors = error.response.data.errors;
                this.message = 'Проверьте заполнение полей';

                return;
            }

            // Всё остальное: 419, 500, обрыв сети. Показывать пользователю
            // response.data.message от 500-й нельзя: при APP_DEBUG=true туда попадает
            // текст исключения с внутренностями приложения.
            this.message = 'Не удалось сохранить пост. Попробуйте ещё раз.';
        },
    },
};
</script>
