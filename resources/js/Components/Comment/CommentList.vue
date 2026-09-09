<template>
    <section class="mt-6 rounded-lg bg-white p-6 shadow">
        <h2 class="mb-4 text-lg font-semibold text-gray-900">
            Комментарии <span class="text-gray-400">{{ total }}</span>
        </h2>

        <CommentForm :post-id="postId" @created="handleCreated" />

        <ItemComment
            v-for="comment in comments"
            :key="comment.id"
            :comment="comment"
        />

        <!--
            Элемент-триггер. Он ВСЕГДА в разметке, даже когда грузить больше нечего:
            за ним следит IntersectionObserver, а наблюдать за узлом, который
            v-if то создаёт, то удаляет, пришлось бы переподключаясь.

            Он же кнопка. Наблюдатель срабатывает на ПЕРЕСЕЧЕНИЕ границы: если после
            подгрузки триггер так и остался на экране, второго срабатывания не будет,
            пока пользователь не прокрутит. Кнопка — честный запасной вариант,
            и она же делает загрузку доступной с клавиатуры.
        -->
        <div ref="trigger" class="py-4 text-center text-sm text-gray-400">
            <span v-if="isLoading">Загружаю…</span>
            <button
                v-else-if="hasMore"
                type="button"
                class="hover:text-sky-700"
                @click="loadMore"
            >
                Загрузить ещё
            </button>
            <span v-else-if="comments.length">Это все комментарии</span>
            <span v-else>Комментариев пока нет</span>
        </div>
    </section>
</template>

<script>
import axios from 'axios';
import CommentForm from '@/Components/Comment/CommentForm.vue';
import ItemComment from '@/Components/Comment/ItemComment.vue';

export default {
    name: 'CommentList',
    components: { CommentForm, ItemComment },
    props: {
        // Только id, а не весь пост: списку от поста больше ничего не нужно,
        // и узкий контракт делает компонент проще для чтения.
        postId: {
            type: Number,
            required: true,
        },
    },
    data() {
        return {
            comments: [],
            total: 0,
            nextPage: 1,
            // null — «ещё ни разу не спрашивали». Отличать это состояние от «страниц
            // больше нет» обязательно: иначе при первом рендере hasMore будет false
            // и первая загрузка не начнётся.
            lastPage: null,
            isLoading: false,
        };
    },
    computed: {
        hasMore() {
            return this.lastPage === null || this.nextPage <= this.lastPage;
        },
    },
    /**
     * mounted — хук жизненного цикла: компонент уже создан И вставлен в DOM.
     * Оба условия важны: до вставки this.$refs.trigger ещё не существует.
     */
    mounted() {
        this.loadMore();

        // IntersectionObserver — браузерный API: он сам следит, попал ли элемент
        // в область просмотра, и зовёт колбэк. Альтернатива — обработчик на scroll
        // с ручным счётом координат: он выполняется на каждый пиксель прокрутки
        // и нагружает главный поток, тогда как наблюдатель работает вне его.
        //
        // this.observer намеренно НЕ объявлен в data(): всё, что там объявлено,
        // Vue делает реактивным и оборачивает в Proxy, а наблюдателю это не нужно —
        // разметка от него не зависит. Обычное поле на экземпляре компонента дешевле
        // и честнее описывает намерение.
        this.observer = new IntersectionObserver(
            (entries) => {
                if (entries[0].isIntersecting) {
                    this.loadMore();
                }
            },
            // rootMargin расширяет область наблюдения на 200px вниз: загрузка
            // стартует до того, как пользователь упрётся в конец списка,
            // и подгрузка успевает произойти незаметно.
            { rootMargin: '200px' },
        );

        this.observer.observe(this.$refs.trigger);
    },
    /**
     * Зеркальный хук: компонент вот-вот исчезнет.
     *
     * disconnect() обязателен. Наблюдатель — объект браузера, он держит ссылку
     * и на DOM-узел, и на колбэк, а колбэк замыкает this. При Inertia-переходе
     * на другую страницу компонент уничтожается, но наблюдатель без disconnect()
     * остаётся жив вместе со всем, на что ссылается, — это утечка памяти.
     */
    beforeUnmount() {
        this.observer?.disconnect();
    },
    methods: {
        loadMore() {
            // Две причины выйти сразу. Первая: запрос уже в пути — наблюдатель
            // легко срабатывает одновременно с вызовом из mounted(), и без этой
            // проверки первая страница приехала бы дважды. Вторая: страниц больше нет.
            if (this.isLoading || !this.hasMore) {
                return;
            }

            this.isLoading = true;

            axios
                .get(route('client.posts.comments.index', this.postId), {
                    // params — это query-строка: ?page=2. У GET нет тела,
                    // поэтому второй аргумент axios.get() — конфиг, а не данные.
                    params: { page: this.nextPage },
                })
                .then((res) => {
                    // Фильтр по уже известным id — защита от дубля на границе страниц.
                    // Set, а не includes(): поиск по множеству не зависит от длины
                    // списка, а список растёт.
                    const known = new Set(this.comments.map((comment) => comment.id));

                    this.comments.push(
                        ...res.data.data.filter((comment) => !known.has(comment.id)),
                    );

                    // Все три значения берём из meta, а не считаем сами: total
                    // приезжает отдельным count(*), и повторить его на клиенте нельзя.
                    this.total = res.data.meta.total;
                    this.lastPage = res.data.meta.last_page;
                    this.nextPage = res.data.meta.current_page + 1;
                })
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
        /**
         * Реакция на событие created от формы.
         *
         * unshift, а не push: список отсортирован «новые сверху», и свежий
         * комментарий должен оказаться первым.
         *
         * total правим на клиенте — это тот редкий случай, когда так можно:
         * мы точно знаем, что записей стало на одну больше, и следующий же
         * запрос за страницей всё равно привезёт настоящее число.
         */
        handleCreated(comment) {
            this.comments.unshift(comment);
            this.total += 1;
        },
    },
};
</script>
