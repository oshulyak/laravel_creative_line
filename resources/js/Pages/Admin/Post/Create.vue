<template>
    <Head title="Создание поста" />

    <Link
        :href="route('admin.posts.index')"
        class="mb-4 inline-block bg-sky-700 px-3 py-2 text-xs text-white hover:bg-sky-800"
    >
        Посты
    </Link>

    <div class="bg-white p-4">
        <!--
            Общая плашка отказа: один блок наверху формы, чтобы отказ заметили,
            даже если проблемное поле уехало за пределы экрана. Здесь и «Проверьте
            заполнение полей» для 422, и текст про сеть/500 — конкретика по полям
            лежит ниже, рядом с самими полями.
        -->
        <p
            v-if="message"
            class="mb-4 border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
            {{ message }}
        </p>

        <!--
            ref нужен для прокрутки в showSuccess(). Элемент создаётся через v-if,
            поэтому в DOM он появляется не в момент присваивания флага, а только
            со следующим тиком.
        -->
        <p
            v-if="isSuccess"
            ref="success"
            class="mb-4 border border-green-200 bg-green-50 p-3 text-sm text-green-700"
        >
            Пост создан.
        </p>

        <!--
            mb-1 вместо mb-4: отступ переезжает на сообщение об ошибке, иначе текст
            висел бы далеко от поля, к которому относится, а без ошибок появлялся бы
            двойной зазор.

            @input, а не @change: у текстового поля change приходит по потере фокуса,
            и ошибка исчезала бы не в момент правки, а при переходе к следующему полю.
        -->
        <input
            v-model="post.title"
            placeholder="title"
            class="mb-1 w-full border border-gray-200 p-4"
            @input="clearFeedback('title')"
        />
        <!--
            v-for по массиву, а не v-if с одним сообщением: Laravel проверяет ВСЕ правила
            поля и складывает все провалы. Заголовок в 300 символов, который вдобавок
            уже занят, провалит и max:255, и unique — показывать только первое сообщение
            значит заставлять пользователя чинить форму в несколько заходов.

            Ошибок нет — errors.title равен undefined, и v-for по нему во Vue 3
            ничего не рисует и не падает. Отдельная проверка не нужна.

            :key — сама строка сообщения: внутри одного поля они не повторяются.
        -->
        <p
            v-for="error in errors.title"
            :key="error"
            class="mb-1 text-xs text-red-600"
        >
            {{ error }}
        </p>

        <textarea
            v-model="post.content"
            placeholder="content"
            class="mb-1 mt-3 w-full border border-gray-200 p-4"
            @input="clearFeedback('content')"
        ></textarea>
        <p
            v-for="error in errors.content"
            :key="error"
            class="mb-1 text-xs text-red-600"
        >
            {{ error }}
        </p>

        <!--
            :value с двоеточием, а не value: без него в модель уедет строка
            ("null" вместо null, "3" вместо 3) и правило integer на бэке заругается.
            disabled делает первый пункт подписью-заглушкой: виден, но выбрать нельзя.

            @change, а не @input: у select события input нет — значение меняется целиком.
        -->
        <select
            v-model="post.category_id"
            class="mb-1 mt-3 w-full border border-gray-200 p-4"
            @change="clearFeedback('category_id')"
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
        <p
            v-for="error in errors.category_id"
            :key="error"
            class="mb-1 text-xs text-red-600"
        >
            {{ error }}
        </p>

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
            class="mb-1 mt-3 w-full border border-gray-200 p-4"
            @change="handleImages"
        />
        <!--
            errors.images существует только благодаря groupErrors(): сервер прислал бы
            ключи images.0 и images.1 — по одному на провалившийся файл, — а ключа images
            в ответе не было бы вовсе.
        -->
        <p
            v-for="error in errors.images"
            :key="error"
            class="mb-1 text-xs text-red-600"
        >
            {{ error }}
        </p>

        <!--
            Теги вводятся одной строкой через запятую и уходят на бэк как есть:
            разбор — правило приложения, а не формы. Клиентов может быть несколько
            (форма, API, импорт), и данным от клиента всё равно нельзя доверять.
        -->
        <textarea
            v-model="tags"
            placeholder="теги через запятую"
            class="mb-1 mt-3 w-full border border-gray-200 p-4"
            @input="clearFeedback('tags')"
        ></textarea>
        <p
            v-for="error in errors.tags"
            :key="error"
            class="mb-1 text-xs text-red-600"
        >
            {{ error }}
        </p>

        <!--
            Флаг saving защищает от двойного клика: второй запрос ушёл бы с тем же
            title и упал бы на unique. Настоящая защита — ранний выход в storePost(),
            а не эти классы: pointer-events-none гасит только мышь, у <a> нет
            атрибута disabled, да и CSS клиент может отключить.
        -->
        <a
            href="#"
            class="mt-3 inline-block bg-teal-700 px-3 py-2 text-xs text-white hover:bg-teal-800"
            :class="{ 'pointer-events-none opacity-60': saving }"
            @click.prevent="storePost"
        >
            {{ saving ? 'Создание…' : 'Создать' }}
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
     *
     * Флагов обратной связи три, и это тот случай, когда четыре свойства понятнее
     * одного «состояния формы» с перечислением: saving — про запрос, errors и message —
     * про отказ, isSuccess — про успех. Каждый гасится в своё время.
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
            // Ошибки по полям, уже свёрнутые к именам полей: { title: [...], images: [...] }.
            errors: {},
            // Общая плашка над формой: и для 422, и для всего остального.
            message: '',
            // Плашка «Пост создан».
            isSuccess: false,
            // Запрос в полёте: блокирует кнопку до ответа сервера.
            saving: false,
        };
    },
    methods: {
        /**
         * Сворачивает ключи ответа к именам полей: images.1 и images.2 → images.
         *
         * Правило images.* порождает ошибку на каждый элемент отдельно, поэтому
         * в ответе приезжает { "images.1": [...] }, а ключа images нет вовсе.
         * Искать ключи по префиксу можно было бы в каждом месте, где ошибки читаются
         * (шаблон, clearFeedback) — тогда формат сервера с точками расползся бы
         * по всему компоненту. Вместо этого приводим чужой формат к своему один раз,
         * на границе: дальше вся форма работает с простым «поле → массив сообщений».
         *
         * Что теряется: машинная привязка сообщения к номеру файла. Пока картинки —
         * один input[type=file] multiple без превью, привязывать сообщение не к чему.
         *
         * @param {Object.<string, string[]>} errors ответ сервера: { 'images.1': [...] }
         * @returns {Object.<string, string[]>} { images: [...] }
         */
        groupErrors(errors) {
            const grouped = {};

            for (const [key, messages] of Object.entries(errors)) {
                // Имя поля — всё до первой точки. Одно выражение покрывает
                // и обычные поля (title → title), и элементы массивов (images.1 → images).
                const field = key.split('.')[0];

                // Сообщения не теряются, а склеиваются: претензии ко второму и третьему
                // файлу окажутся в одном массиве и выведутся двумя абзацами.
                grouped[field] = [...(grouped[field] ?? []), ...messages];
            }

            return grouped;
        },
        /**
         * Гасит всю обратную связь по полю, как только пользователь начал его править:
         * сообщения об ошибке, общую плашку и «Пост создан». Красная подпись под полем,
         * которое уже исправили, — враньё интерфейса.
         *
         * Точечная очистка, а не this.errors = {}: обнулять всё разом проще, но тогда
         * правка заголовка убрала бы с экрана и жалобу на пустой контент — пользователь
         * решит, что всё в порядке, и отправит форму второй раз впустую.
         *
         * delete по ключу реактивного объекта во Vue 3 работает штатно: data() отдана
         * под Proxy, у которого есть ловушка deleteProperty. Во Vue 2 для этого нужен
         * был Vue.delete / this.$delete — в Vue 3 таких хелперов больше нет.
         *
         * Одна строка вместо перебора ключей — заслуга groupErrors(): ключ images
         * в errors один, а не images.0, images.1 и images.2 по отдельности.
         */
        clearFeedback(field) {
            delete this.errors[field];

            this.message = '';
            this.isSuccess = false;
        },
        handleImages(event) {
            // event.target.files — FileList, а не массив: Array.from даёт настоящий массив.
            this.images = Array.from(event.target.files);
            // Выбрали новые файлы — уходят все сообщения по картинкам сразу: выбор
            // заменяет весь список целиком, старые претензии относятся к файлам,
            // которых в форме больше нет.
            this.clearFeedback('images');
        },
        storePost() {
            // Ранний выход, а не только классы в шаблоне: он отсекает и повторный клик,
            // проскочивший до перерисовки, и вызов метода из любого другого места.
            if (this.saving) {
                return;
            }

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

            // Чистим обратную связь перед новой попыткой: и ошибки прошлого раза,
            // и зелёную плашку — иначе рядом с новой ошибкой висело бы «Пост создан»,
            // а пока запрос в полёте, эта надпись относится к прошлому посту
            // и перестала быть правдой.
            //
            // Гашение здесь же избавляет showSuccess() от сброса флага: к моменту
            // ответа плашки на экране нет, и ответ вернёт её честным переходом
            // false → true, который Vue нарисует. Сбрасывать флаг в момент показа
            // бесполезно — оба присваивания уложились бы в один кадр.
            this.errors = {};
            this.message = '';
            this.isSuccess = false;
            // saving = true сразу: пользователь видит, что клик засчитан,
            // не дожидаясь ответа сервера.
            this.saving = true;

            // Приложение — монолит, поэтому URL берём у Ziggy по имени роута.
            axios
                .post(route('admin.posts.store'), formData)
                .then(() => {
                    // Сначала убрали, потом сообщили: resetForm() синхронный,
                    // showSuccess() — асинхронный из-за await.
                    this.resetForm();
                    this.showSuccess();
                })
                // Метод компонента уже привязан к экземпляру — Vue делает это
                // при инициализации, так что this внутри не потеряется,
                // и обёртка (e) => this.handleError(e) ничего не добавила бы.
                .catch(this.handleError)
                // finally, а не разблокировка в каждой ветке: в отличие от Edit.vue
                // мы остаёмся на странице, никакой router.visit() кнопку не переживает.
                .finally(() => {
                    this.saving = false;
                });
        },
        resetForm() {
            // Форму чистим только после подтверждения сервера: при ошибке введённые
            // данные должны остаться, чтобы их можно было исправить.
            //
            // Присваивание не порождает событий input и change — их создаёт браузер
            // в ответ на действия пользователя. Поэтому очистка формы не запустит
            // clearFeedback() и только что показанную плашку не погасит.
            this.post = {
                title: '',
                content: '',
                category_id: null,
            };
            this.images = [];
            this.tags = '';
            // input[type=file] сам не сбрасывается — только через ref.
            this.$refs.imagesInput.value = '';
        },
        /**
         * Показывает плашку «Пост создан» и подводит к ней экран.
         *
         * Vue не трогает DOM в момент присваивания: изменения складываются в очередь
         * и применяются пачкой в следующем «тике». nextTick() — обещание, которое
         * исполняется после того, как очередь разобрана и DOM уже перерисован.
         * Тик — это позиция в очереди микрозадач, а не задержка в миллисекундах.
         */
        async showSuccess() {
            this.isSuccess = true;

            // Тик нужен ровно для следующей строки: элемент создаётся через v-if, и сразу
            // после присваивания this.$refs.success ещё undefined — прокручивать нечего.
            // Без аргумента $nextTick() возвращает промис, отсюда await вместо колбэка.
            await this.$nextTick();

            // ?. — дешёвая страховка: поменяют разметку, ref исчезнет — метод
            // не упадёт с TypeError.
            this.$refs.success?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        },
        handleError(error) {
            // 422 Unprocessable Entity — «запрос понят, но значения не годятся»:
            // FormRequest вернул { message, errors }. Читаем errors (объект с массивами),
            // а не message — та строка это «первая ошибка плюс счётчик», заголовок,
            // а не текст рядом с полем.
            //
            // response? обязателен: если запрос не дошёл до сервера (обрыв сети, CORS),
            // у ошибки axios поля response нет вовсе, и обработчик упал бы с TypeError.
            if (error.response?.status === 422) {
                // Единственное место, где формат сервера превращается в наш.
                this.errors = this.groupErrors(error.response.data.errors);
                this.message = 'Проверьте заполнение полей';

                return;
            }

            // 419, 500, обрыв сети. Показывать response.data.message от 500-й нельзя:
            // при APP_DEBUG=true туда попадает текст исключения с внутренностями приложения.
            this.message = 'Не удалось создать пост. Попробуйте ещё раз.';
        },
    },
};
</script>
