<template>
    <!--
        Один корневой элемент. Vue 3 разрешает несколько, но тогда «лишним»
        атрибутам (class со стороны родителя, слушателям) некуда приземлиться —
        Vue не угадает, какому корню они предназначены. Карточка — цельный блок,
        корень у неё естественным образом один.
    -->
    <article class="mb-4 rounded-lg bg-white p-5 shadow">
        <!--
            postData.author?.nickname, а не postData.author.nickname: ключ приходит
            из whenLoaded() и при незагруженной связи его в пропсах вообще нет —
            обращение к свойству у undefined уронит рендер страницы.
        -->
        <p class="mb-1 text-xs uppercase tracking-wider text-gray-400">
            {{ postData.author?.nickname ?? 'Аноним' }} ·
            {{ postData.category?.title ?? 'Без категории' }}
        </p>

        <!--
            Link, а не <a href>: обычная ссылка перезагрузила бы страницу целиком,
            Link делает XHR и подменяет только компонент страницы.
        -->
        <Link
            :href="route('client.posts.show', postData.id)"
            class="text-lg font-semibold text-gray-900 hover:text-sky-700"
        >
            {{ postData.title }}
        </Link>

        <!--
            Бейдж переехал сюда со страницы «Мои публикации». В ленте условие
            не выполнится никогда — там только опубликованное, — и это нормально:
            карточка описывает пост, а не страницу, на которой её показывают.

            Сравнение с числом 1 — тот же шов между сервером и клиентом:
            константа Post::STATUS_PUBLISHED живёт в PHP.
        -->
        <span
            v-if="postData.status !== 1"
            class="ml-2 rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700"
        >
            На модерации
        </span>

        <p class="mt-2 whitespace-pre-line text-sm text-gray-700">
            {{ excerpt(postData.content) }}
        </p>

        <footer class="mt-3 flex items-center gap-4">
            <!--
                Кнопка лайка переехала со страницы поста почти без изменений.
                Заметьте, что isLikePending не мешает соседним карточкам: у каждого
                экземпляра компонента своё data(). В админском списке ради того же
                эффекта пришлось держать deletingId и сравнивать его с id строки —
                компонент снимает эту заботу.
            -->
            <button
                type="button"
                :disabled="isLikePending"
                :aria-pressed="postData.is_liked"
                class="inline-flex items-center gap-2 text-sm disabled:opacity-50"
                :class="postData.is_liked ? 'text-rose-600' : 'text-gray-400 hover:text-rose-500'"
                @click="toggleLike"
            >
                <svg
                    class="h-5 w-5"
                    viewBox="0 0 24 24"
                    :fill="postData.is_liked ? 'currentColor' : 'none'"
                    stroke="currentColor"
                    stroke-width="1.5"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"
                    />
                </svg>

                <span>{{ postData.likes_count }}</span>
            </button>

            <!--
                v-if по флагу с сервера, а не по сравнению id на клиенте:
                решение «можно ли удалить» принимает PHP, Vue только рисует.

                class="ml-auto" на компоненте — не проп: Vue сам перенесёт его
                на корневой элемент DeletePost. Это fallthrough-атрибуты; так же
                пролетают style, id и обычные слушатели DOM-событий.

                @deleted — слушатель СВОЕГО события компонента, а не DOM-события.
                Такого события у браузера нет, его придумали мы.
            -->
            <DeletePost
                v-if="postData.can_delete"
                :post="postData"
                class="ml-auto"
                @deleted="handleDeleted"
            />
        </footer>
    </article>
</template>

<script>
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import DeletePost from '@/Components/Post/DeletePost.vue';

export default {
    name: 'ItemPost',
    // Регистрация локальная, а не глобальная (app.component(...) в app.js):
    // компонент нужен только здесь, и по списку components видно, из чего
    // собран шаблон. Глобально регистрируют то, что встречается почти везде.
    components: { Link, DeletePost },
    // inject — обработчик, который положила страница через provide().
    // Объектная форма (а не inject: ['onPostDeleted']) нужна ради default:
    // без него Vue напишет в консоль «injection not found» и подставит undefined.
    // Значение по умолчанию делает компонент пригодным и там, где страница
    // ничего не предоставила.
    inject: {
        onPostDeleted: { default: null },
    },
    props: {
        // Контракт компонента: он ждёт объект поста в том виде, в каком его отдаёт
        // PostResource — с author, category, likes_count, is_liked и can_delete.
        // required: true, потому что карточки без поста не бывает.
        post: {
            type: Object,
            required: true,
        },
    },
    data() {
        return {
            // Локальная копия пропса: лайк меняет is_liked и likes_count, а писать
            // в проп нельзя — поток данных односторонний. Копия поверхностная,
            // и этого достаточно: меняем только два скалярных поля.
            postData: { ...this.post },
            isLikePending: false,
        };
    },
    watch: {
        /**
         * Пересобирает локальную копию, когда с сервера приезжают свежие данные.
         *
         * data() выполняется один раз — при создании компонента. Карточка живёт
         * в v-for с :key="post.id", а router.reload() сохраняет состояние страницы,
         * поэтому уцелевшие карточки Vue переиспользует: экземпляр тот же,
         * data() второй раз не вызывается. Без наблюдателя в postData остались бы
         * счётчики, приехавшие при первой загрузке страницы.
         */
        post(value) {
            this.postData = { ...value };
        },
    },
    methods: {
        toggleLike() {
            this.isLikePending = true;

            axios
                .post(route('client.posts.likes.toggle', this.postData.id))
                .then((res) => {
                    // Оба значения берём из ответа, а не считаем на клиенте:
                    // пост могли лайкнуть другие, пока страница была открыта.
                    this.postData.is_liked = res.data.is_liked;
                    this.postData.likes_count = res.data.likes_count;
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isLikePending = false;
                });
        },
        /**
         * Реакция на событие от DeletePost.
         *
         * Карточка не решает, что делать дальше, — она передаёт факт странице.
         * Лента перезапросит список, страница поста сделала бы редирект.
         *
         * ?. на случай, если страница не сделала provide(): вместо падения
         * просто ничего не произойдёт.
         */
        handleDeleted(postId) {
            this.onPostDeleted?.(postId);
        },
        /**
         * Короткий анонс поста. Обрезаем на клиенте, потому что на странице поста
         * нужен полный текст, и второй выборки ради превью делать не хочется.
         */
        excerpt(content, length = 200) {
            return content.length > length ? `${content.slice(0, length)}…` : content;
        },
    },
};
</script>
