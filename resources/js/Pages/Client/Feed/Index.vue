<template>
    <Head title="Лента" />

    <header class="mb-4 flex items-baseline justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Лента</h1>
        <span class="text-sm text-gray-500">Всего: {{ posts.meta.total }}</span>
    </header>

    <p v-if="!posts.data.length" class="rounded-lg bg-white p-6 text-sm text-gray-500">
        Публикаций пока нет.
    </p>

    <!--
        Вся разметка карточки уехала в ItemPost — вместе с лайком, бейджем статуса
        и кнопкой удаления. Странице остались заголовок, список и пагинация.

        :key обязателен и в списке компонентов: по нему Vue понимает, какая карточка
        какому посту соответствует. С ключами по id после удаления исчезнет ровно одна
        карточка, а остальные экземпляры уцелеют вместе со своим состоянием.
        С :key="index" Vue переиспользовал бы карточки по позиции, и состояние
        («лайк отправляется») уехало бы к соседнему посту.
    -->
    <ItemPost v-for="post in posts.data" :key="post.id" :post="post" />

    <!--
        Пагинация ссылками, а не axios: в ленте нет фильтра, поэтому и локального
        состояния запроса нет — достаточно перейти на /feed?page=2. Link делает
        Inertia-переход, сервер отдаёт новые пропсы, раскладка остаётся на месте.

        link.url, а не link.page: в отличие от админки, номер страницы никуда подставлять
        не нужно — пагинатор уже собрал готовый URL. У «...» и у неактивных стрелок
        url равен null, поэтому такие элементы показываем span'ом.

        v-html вместо {{ }}: в label лежат HTML-сущности &laquo; и &raquo; из языкового
        файла фреймворка, интерполяция показала бы их буквально. Источник строки —
        сам Laravel, а не пользовательский ввод, поэтому XSS тут нет.
    -->
    <nav v-if="posts.meta.last_page > 1" class="mt-6 flex flex-wrap gap-1">
        <template v-for="(link, index) in posts.meta.links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
                class="border px-3 py-2 text-sm"
                :class="
                    link.active
                        ? 'border-sky-800 bg-sky-700 text-white'
                        : 'border-gray-300 bg-white hover:bg-gray-50'
                "
                v-html="link.label"
            />
            <span
                v-else
                class="border border-gray-200 px-3 py-2 text-sm text-gray-300"
                v-html="link.label"
            />
        </template>
    </nav>
</template>

<script>
import { Head, Link, router } from '@inertiajs/vue3';
import ClientLayout from '@/Layouts/ClientLayout.vue';
import ItemPost from '@/Components/Post/ItemPost.vue';

export default {
    name: 'Index',
    // layout — свойство Inertia, а не Vue: при SPA-переходе раскладка не пересоздаётся,
    // меняется только содержимое её <slot />.
    layout: ClientLayout,
    components: { Head, Link, ItemPost },
    // provide — функцией, а не объектом: только так внутри доступен this.
    // Выполняется один раз при создании компонента, уже после methods и data,
    // поэтому ссылка на метод к этому моменту существует.
    //
    // Передаём функцию, а не данные: переданное через provide значение
    // не реактивно, и класть сюда что-то меняющееся было бы ошибкой.
    //
    // Пара provide/inject нужна, чтобы событие от кнопки удаления не пришлось
    // пересылать вручную через каждый промежуточный компонент: DeletePost
    // излучает deleted, ItemPost его ловит и зовёт обработчик страницы.
    provide() {
        return {
            onPostDeleted: this.reloadPosts,
        };
    },
    props: {
        posts: {
            // Object, а не Array: постраничный ответ — это { data, links, meta }.
            type: Object,
            // Заглушка повторяет форму настоящего ответа: шаблон обращается
            // к meta.total и meta.links.
            default: () => ({ data: [], meta: { links: [], last_page: 1, total: 0 } }),
        },
    },
    methods: {
        /**
         * Реакция ленты на удаление поста: перезапросить список у сервера.
         *
         * Выбросить пост из массива на клиенте нельзя: meta.total останется прежним,
         * кнопок пагинации будет на одну больше, чем нужно, а запись, которая должна
         * подняться со следующей страницы, на клиент просто не приезжала.
         *
         * reload() — это visit() по текущему URL: /feed?page=2 останется /feed?page=2.
         * only: ['posts'] — частичная перезагрузка: Inertia шлёт заголовок
         * X-Inertia-Partial-Data, и в ответ попадёт только этот проп.
         */
        reloadPosts() {
            router.reload({ only: ['posts'] });
        },
    },
};
</script>
