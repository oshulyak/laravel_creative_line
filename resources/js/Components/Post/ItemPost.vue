<template>
    <!--
        Один корневой элемент. Vue 3 разрешает несколько, но тогда «лишним»
        атрибутам (class со стороны родителя, слушателям) некуда приземлиться —
        Vue не угадает, какому корню они предназначены. Карточка — цельный блок,
        корень у неё естественным образом один.
    -->
    <article class="mb-4 rounded-lg bg-white p-5 shadow">
        <!--
            Ник автора — ссылка на страницу его профиля.

            v-if по самому объекту author, а не постфикс ?.: ключ приходит
            из whenLoaded() и при незагруженной связи его в пропсах вообще нет.
            Ссылке нужен ещё и author.id, а построить маршрут с undefined
            route() не сможет — Ziggy бросит ошибку и уронит рендер.
        -->
        <p class="mb-1 text-xs uppercase tracking-wider text-gray-400">
            <Link
                v-if="postData.author"
                :href="route('client.profiles.show', postData.author.id)"
                class="hover:text-sky-700"
            >
                {{ postData.author.nickname }}
            </Link>
            <span v-else>Аноним</span>
            · {{ postData.category?.title ?? 'Без категории' }}
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

        <!--
            Тело карточки. У обычного поста это просто абзац с текстом; у репоста
            тот же абзац получает сдвиг вправо, вертикальную линию слева и мягкую
            подложку — сразу видно, что текст пришёл с чужой публикации.

            Обёртка нужна, чтобы линия и фон охватили и подпись, и текст одним
            блоком: два отдельных элемента со своими рамками разошлись бы
            на отступе между ними.

            Статический class остаётся статическим, а :class добавляет к нему
            оформление вложенности — Vue объединяет оба атрибута, а не заменяет
            один другим.
        -->
        <div
            class="mt-2"
            :class="
                postData.parent
                    ? 'rounded-r-lg border-l-4 border-sky-200 bg-sky-50/60 py-2 pl-4 pr-3'
                    : ''
            "
        >
            <!--
                Строка появляется только у репостов: у обычного поста ключа parent
                в JSON нет вовсе (whenLoaded), и v-if не выполнится.

                Второй случай, который гасит тот же v-if, — удалённый оригинал: тогда
                parent приедет как null (в базе сработал nullOnDelete). Репост остаётся
                обычным постом, и подписывать его нечем — вместе с подписью пропадёт
                и рамка с подложкой: :class смотрит на то же самое поле.
            -->
            <p v-if="postData.parent" class="mb-1 text-xs text-gray-500">
                Репост:
                <Link
                    :href="route('client.posts.show', postData.parent.id)"
                    class="text-sky-700 hover:underline"
                >
                    {{ postData.parent.title }}
                </Link>
                · {{ postData.parent.author?.nickname ?? 'Аноним' }}
            </p>

            <p class="whitespace-pre-line text-sm text-gray-700">
                {{ excerpt(postData.content) }}
            </p>
        </div>

        <footer class="mt-3 flex items-center gap-4">
            <!--
                Кнопка лайка уехала в общий LikeButton: та же логика нужна теперь
                и комментарию, а третья копия — это уже перебор. Наружу компонент
                получает готовый URL, а не пост: он не должен знать, что лайкает.

                Своё isLikePending живёт внутри кнопки, поэтому соседние карточки
                по-прежнему не мешают друг другу — у каждого экземпляра своё data().
            -->
            <LikeButton
                :url="route('client.posts.likes.toggle', postData.id)"
                :initial-liked="postData.is_liked"
                :initial-count="postData.likes_count"
                class="text-sm"
            />

            <!--
                Кнопка репоста: иконка со счётчиком, модалка живёт внутри неё.
                Компонент получает пост целиком, а не готовый url, — ему нужен
                заголовок оригинала для окна.
            -->
            <RepostButton
                :post="postData"
                :initial-count="postData.reposts_count"
                @reposted="handleReposted"
            />

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
import { Link } from '@inertiajs/vue3';
import DeletePost from '@/Components/Post/DeletePost.vue';
import LikeButton from '@/Components/LikeButton.vue';
import RepostButton from '@/Components/Post/RepostButton.vue';

export default {
    name: 'ItemPost',
    // Регистрация локальная, а не глобальная (app.component(...) в app.js):
    // компонент нужен только здесь, и по списку components видно, из чего
    // собран шаблон. Глобально регистрируют то, что встречается почти везде.
    components: { Link, DeletePost, LikeButton, RepostButton },
    // inject — обработчик, который положила страница через provide().
    // Объектная форма (а не inject: ['onPostDeleted']) нужна ради default:
    // без него Vue напишет в консоль «injection not found» и подставит undefined.
    // Значение по умолчанию делает компонент пригодным и там, где страница
    // ничего не предоставила.
    inject: {
        onPostDeleted: { default: null },
        onPostReposted: { default: null },
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
         * Реакция на событие от RepostButton.
         *
         * Карточка снова не решает, что делать, — передаёт факт странице. Тот же
         * приём, что с удалением: на «Моих публикациях» список нужно перезапросить,
         * чтобы свежий репост появился сразу, а не после F5.
         */
        handleReposted() {
            this.onPostReposted?.();
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
