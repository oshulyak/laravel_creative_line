<template>
    <Head title="Posts" />

    <header class="mb-6 flex items-baseline justify-between">
        <h3 class="text-2xl font-semibold text-gray-900">Posts</h3>
        <span class="text-sm text-gray-500">Всего: {{ postsData.length }}</span>
    </header>

    <Link
        :href="route('admin.posts.create')"
        class="mb-4 inline-block border border-sky-800 bg-sky-700 px-3 py-2 text-xs text-white hover:bg-sky-800"
    >
        Создать
    </Link>

    <!--
        Фильтр без кнопки: элемента submit здесь нет вообще, запрос уходит
        по изменению любого поля (watch + debounce в <script>). Обёртку <form>
        не ставим сознательно — без неё Enter в текстовом поле не может
        случайно перезагрузить страницу.
    -->
    <div class="mb-4 grid grid-cols-1 gap-3 bg-white p-4 sm:grid-cols-3">
        <label class="block">
            <span class="mb-1 block text-xs uppercase tracking-wider text-gray-500">
                Заголовок
            </span>
            <input
                v-model="filter.title"
                type="text"
                placeholder="часть заголовка"
                class="w-full border border-gray-300 px-3 py-2 text-sm"
            />
        </label>

        <label class="block">
            <span class="mb-1 block text-xs uppercase tracking-wider text-gray-500">
                Опубликован с
            </span>
            <!--
                type="date" даёт браузерный date picker и всегда отдаёт в v-model
                строку вида 2026-06-01 — под неё написано правило date_format:Y-m-d.
            -->
            <input
                v-model="filter.published_at_from"
                type="date"
                class="w-full border border-gray-300 px-3 py-2 text-sm"
            />
        </label>

        <label class="block">
            <span class="mb-1 block text-xs uppercase tracking-wider text-gray-500">
                Лайков не меньше
            </span>
            <input
                v-model.number="filter.likes_from"
                type="number"
                min="0"
                class="w-full border border-gray-300 px-3 py-2 text-sm"
            />
        </label>
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium">ID</th>
                        <th class="px-4 py-3 text-left font-medium">Превью</th>
                        <th class="px-4 py-3 text-left font-medium">Заголовок</th>
                        <th class="px-4 py-3 text-left font-medium">Категория</th>
                        <th class="px-4 py-3 text-left font-medium">Автор</th>
                        <th class="px-4 py-3 text-left font-medium">Опубликован</th>
                        <th class="px-4 py-3 text-left font-medium">Лайки</th>
                        <th class="px-4 py-3 text-right font-medium">Действия</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    <tr
                        v-for="post in postsData"
                        :key="post.id"
                        class="align-top transition-colors hover:bg-gray-50"
                    >
                        <td class="whitespace-nowrap px-4 py-4 font-mono text-gray-400">
                            {{ post.id }}
                        </td>

                        <td class="px-4 py-4">
                            <img
                                v-if="post.img_path"
                                :src="post.img_path"
                                :alt="post.title"
                                class="h-12 w-12 rounded object-cover"
                            />
                            <div
                                v-else
                                class="flex h-12 w-12 items-center justify-center rounded bg-gray-100 text-xs text-gray-400"
                            >
                                нет
                            </div>
                        </td>

                        <!--
                            Link, а не <a href>: обычная ссылка перезагрузила бы страницу
                            целиком, Link делает XHR и подменяет только компонент страницы.
                            Второй аргумент route() — значение сегмента {post}.
                        -->
                        <td class="max-w-md px-4 py-4">
                            <Link
                                :href="route('admin.posts.show', post.id)"
                                class="font-medium text-sky-700 hover:underline"
                            >
                                {{ post.title }}
                            </Link>
                            <p class="mt-1 text-gray-500">{{ excerpt(post.content) }}</p>
                        </td>

                        <td class="whitespace-nowrap px-4 py-4">
                            <span
                                v-if="post.category"
                                class="inline-flex rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700"
                            >
                                {{ post.category.title }}
                            </span>
                            <span v-else class="text-gray-400">—</span>
                        </td>

                        <td class="whitespace-nowrap px-4 py-4 text-gray-500">
                            #{{ post.author_id }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-4 text-gray-500">
                            {{ formatDate(post.published_at) ?? '—' }}
                        </td>

                        <!--
                            ?? 0 — потому что ключ likes_count в ответе не гарантирован:
                            ресурс отдаёт его через whenCounted(), то есть только там,
                            где вызван withCount(). Без него в ячейке было бы пусто.
                        -->
                        <td class="whitespace-nowrap px-4 py-4 text-gray-500">
                            {{ post.likes_count ?? 0 }}
                        </td>

                        <!--
                            Ссылка, а не кнопка: переход на форму редактирования —
                            обычный GET. Второй аргумент route() — сегмент {post}.
                        -->
                        <td class="whitespace-nowrap px-4 py-4 text-right">
                            <Link
                                :href="route('admin.posts.edit', post.id)"
                                class="inline-block bg-amber-600 px-3 py-2 text-xs text-white hover:bg-amber-700"
                            >
                                Редактировать
                            </Link>
                        </td>
                    </tr>

                    <tr v-if="!postsData.length">
                        <td colspan="8" class="px-4 py-10 text-center text-gray-500">
                            Публикаций пока нет.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
    name: 'Index',
    layout: AdminLayout,
    components: { Head, Link },
    props: {
        posts: {
            type: Array,
            default: () => [],
        },
    },
    data() {
        return {
            // Ключи в snake_case не случайно: filter — не внутреннее состояние компонента,
            // а набор query-параметров. Его ключи уезжают в URL как есть и обязаны совпадать
            // с правилами Admin\Post\IndexRequest и со списком $keys у PostFilter.
            filter: {
                title: '',
                published_at_from: '',
                likes_from: null,
            },
            // Что показывает таблица: сначала то, что приехало пропсом, потом — ответ сервера.
            // Пропс менять нельзя (однонаправленный поток данных), поэтому нужна своя копия.
            // Копия здесь — тот же массив, а не глубокий клон; безопасно только потому,
            // что мы всегда заменяем postsData целиком, а не мутируем его.
            postsData: this.posts,
            // Идентификатор отложенного вызова filterPosts() для debounce.
            timerId: null,
        };
    },
    watch: {
        filter: {
            // Каждое изменение отменяет предыдущий отложенный вызов и ставит новый:
            // запрос уходит через 400 мс после последнего нажатия клавиши, а не на каждую букву.
            handler() {
                clearTimeout(this.timerId);
                this.timerId = setTimeout(this.filterPosts, 400);
            },
            // Без deep наблюдатель молчит: при вводе меняется свойство filter.title,
            // а ссылка на сам объект filter остаётся прежней.
            deep: true,
        },
    },
    methods: {
        filterPosts() {
            // params вместо ручной сборки строки: axios сам закодирует значения,
            // а ключи со значением null просто не отправит.
            axios
                .get(route('admin.posts.index'), { params: this.filter })
                .then((res) => {
                    // res.data — массив из PostResource::collection()->resolve():
                    // страница не перерисовывается, меняется только эта переменная.
                    this.postsData = res.data;
                })
                .catch((e) => {
                    // 422 — ошибка в значениях фильтра. Список оставляем как есть:
                    // затирать его пустым массивом при ошибке хуже, чем не делать ничего.
                    console.log(e.response?.data);
                });
        },
        excerpt(text, limit = 140) {
            if (!text) return '';
            return text.length > limit ? `${text.slice(0, limit).trimEnd()}…` : text;
        },
        formatDate(value) {
            return (!value)? null :
            // '2026-06-28 11:20:01' → ISO-подобный вид, который разбирают все браузеры.
            new Date(value.replace(' ', 'T')).toLocaleString('ru-RU', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        },
    },
};
</script>
