<template>
    <Head title="Posts" />

    <header class="mb-6 flex items-baseline justify-between">
        <h3 class="text-2xl font-semibold text-gray-900">Posts</h3>
        <!--
            meta.total — не длина массива на экране, а число постов, подошедших
            под фильтр целиком. Ради него пагинатор и делает второй запрос count(*).
        -->
        <span class="text-sm text-gray-500">Всего: {{ postsData.meta.total }}</span>
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
                v-model="entries.filters.title"
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
                v-model="entries.filters.published_at_from"
                type="date"
                class="w-full border border-gray-300 px-3 py-2 text-sm"
            />
        </label>

        <label class="block">
            <span class="mb-1 block text-xs uppercase tracking-wider text-gray-500">
                Лайков не меньше
            </span>
            <input
                v-model.number="entries.filters.likes_from"
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
                        v-for="post in postsData.data"
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
                            <!--
                                button, а не Link: удаление — не переход по адресу, а действие.
                                Ссылка на DELETE-маршрут вообще не сработала бы: браузер ходит
                                по ссылкам методом GET.

                                deletingId, а не общий флаг: строк на экране несколько,
                                и блокировать нужно только ту кнопку, по которой кликнули.
                            -->
                            <button
                                type="button"
                                :disabled="deletingId === post.id"
                                class="ml-2 inline-block bg-red-600 px-3 py-2 text-xs text-white hover:bg-red-700 disabled:bg-red-300"
                                @click="destroyPost(post)"
                            >
                                Удалить
                            </button>
                        </td>
                    </tr>

                    <tr v-if="!postsData.data.length">
                        <td colspan="8" class="px-4 py-10 text-center text-gray-500">
                            Публикаций пока нет.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!--
        Нижняя панель списка: слева номера страниц, справа размер страницы.
        v-if стоит на самих кнопках, а не на этой обёртке: селект нужен всегда,
        иначе после выбора «по 25» список схлопнется в одну страницу, кнопки
        исчезнут вместе с селектом — и вернуться к «по 5» станет нечем.
    -->
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <!--
            Кнопки страниц строим по meta.links — Laravel уже посчитал, какие номера
            показать, какой активен и где поставить «...». Одна страница — переключатель
            не нужен, но meta.links в этом случае всё равно вернёт «Previous», «1», «Next»,
            поэтому нужна проверка last_page.
        -->
        <nav v-if="postsData.meta.last_page > 1" class="flex flex-wrap gap-1">
            <!--
                :key="index", а не link.page: у «...» и у неактивных стрелок page равен null,
                и ключи бы совпали. Список статичен по структуре — он не сортируется,
                а перерисовывается целиком, поэтому индекс здесь допустим.

                :disabled — у разделителя и у неактивных стрелок url равен null, кликать
                по ним нечего; активную страницу блокируем, чтобы не перезапрашивать
                то, что уже на экране.

                v-html вместо {{ }}: в label лежат HTML-сущности &laquo; и &raquo; из
                языкового файла фреймворка, интерполяция показала бы их буквально.
                Источник строки — сам Laravel, а не пользовательский ввод, поэтому XSS тут нет.

                link.page, а не link.label: в page уже лежит номер числом. Разбор label
                строкой отправил бы в запрос «&laquo; Previous» и получил 422.
            -->
            <button
                v-for="(link, index) in postsData.meta.links"
                :key="index"
                type="button"
                :disabled="!link.url || link.active"
                class="border px-3 py-2 text-sm disabled:cursor-default disabled:text-gray-300"
                :class="
                    link.active
                        ? 'border-sky-800 bg-sky-700 text-white'
                        : 'border-gray-300 bg-white hover:bg-gray-50'
                "
                @click="entries.pagination.page = link.page"
                v-html="link.label"
            ></button>
        </nav>

        <!--
            ml-auto, а не justify-between у обёртки: прижимать селект вправо должен
            он сам, иначе при спрятанных кнопках он уехал бы в левый край.
        -->
        <label class="ml-auto flex items-center gap-5 text-sm text-gray-500">
            <span>На странице</span>
            <!--
                :value="5", а не value="5": без двоеточия в <option> уехала бы строка,
                и v-model.number потерял бы смысл.

                @change сбрасывает страницу: после «по 25 записей» третьей страницы
                может уже не существовать. Наблюдатель за pagination при этом сработает
                один раз — оба изменения попадут в один debounce.

                Ширина задана явно (w-24 = 96px вместо ≈64px по содержимому): без неё
                select менял бы размер вслед за длиной выбранного варианта, и соседние
                кнопки пагинации дёргались бы при каждом переключении.
            -->
            <select
                v-model.number="entries.pagination.per_page"
                class="w-12 border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900"
                @change="entries.pagination.page = 1"
            >
                <option :value="5">5</option>
                <option :value="10">10</option>
                <option :value="25">25</option>
            </select>
        </label>
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
            // Object, а не Array: постраничный ответ — это { data, links, meta }.
            type: Object,
            // Заглушка обязана повторять форму настоящего ответа: шаблон обращается
            // к postsData.meta.links и meta.total ещё до первого запроса.
            // Фабрика, а не литерал: иначе все экземпляры делили бы один объект.
            default: () => ({ data: [], meta: { links: [], total: 0 } }),
        },
    },
    data() {
        return {
            // Структура повторяет контракт IndexRequest: два блока, snake_case внутри.
            // Весь объект целиком уезжает в params, поэтому имена ключей обязаны совпадать
            // с правилами валидации и со списком $keys у PostFilter.
            entries: {
                filters: {
                    title: '',
                    published_at_from: '',
                    likes_from: null,
                },
                pagination: {
                    page: 1,
                    per_page: 5,
                },
            },
            // Ответ сервера целиком: { data, links, meta }. Сначала то, что приехало пропсом,
            // потом — то, что вернул axios; форма у них одинаковая. Пропс менять нельзя
            // (однонаправленный поток данных), поэтому нужна своя копия. Копия здесь — та же
            // ссылка, а не глубокий клон; безопасно только потому, что мы всегда заменяем
            // postsData целиком, а не мутируем его.
            postsData: this.posts,
            // Идентификатор отложенного вызова filterPosts() для debounce.
            timerId: null,
            // id поста, который сейчас удаляется: блокирует только его кнопку.
            deletingId: null,
        };
    },
    watch: {
        // Имя наблюдаемого — путь, а не только корневой ключ: 'entries.filters' избавляет
        // от промежуточного computed. deep нужен обоим по той же причине, что и в 19-м уроке:
        // меняется свойство внутри объекта, а ссылка на сам объект остаётся прежней.
        'entries.filters': {
            handler() {
                // Новый фильтр — снова с первой страницы: на третьей может уже не остаться
                // результатов, и пользователь увидел бы пустой список.
                this.entries.pagination.page = 1;
                this.scheduleFilterPosts();
            },
            deep: true,
        },
        // Отдельный наблюдатель, а не общий за entries: общий не различает, что именно
        // изменилось, и сбрасывал бы page = 1 в том числе при клике по кнопке «2».
        'entries.pagination': {
            handler() {
                this.scheduleFilterPosts();
            },
            deep: true,
        },
    },
    methods: {
        /**
         * Общая точка входа для обоих наблюдателей: откладывает запрос на 400 мс
         * и отменяет предыдущий отложенный. Заодно склеивает два срабатывания
         * (смена фильтра + сброс страницы) в один запрос — если бы наблюдатели звали
         * filterPosts() напрямую, в сеть ушли бы два одинаковых GET подряд.
         */
        scheduleFilterPosts() {
            clearTimeout(this.timerId);
            this.timerId = setTimeout(this.filterPosts, 400);
        },
        filterPosts() {
            // params: this.entries — axios сам развернёт вложенный объект
            // в filters[title]=…&pagination[page]=2, а PHP разберёт это обратно в массив.
            // Ключи со значением null он не отправит вовсе.
            axios
                .get(route('admin.posts.index'), { params: this.entries })
                .then((res) => {
                    // res.data — это { data, links, meta }: строка не изменилась
                    // с 19-го урока, изменилась форма того, что в неё кладётся.
                    this.postsData = res.data;
                })
                .catch((e) => {
                    // 422 — ошибка в значениях фильтра или пагинации. Список оставляем
                    // как есть: затирать его пустым при ошибке хуже, чем не делать ничего.
                    console.log(e.response?.data);
                });
        },
        destroyPost(post) {
            // Удаление необратимо, поэтому спрашиваем. confirm() — заглушка: он блокирует
            // вкладку и выглядит по-разному в разных браузерах, но пока честнее
            // самодельного модального окна, которое пришлось бы писать с нуля.
            if (! confirm(`Удалить пост «${post.title}»?`)) {
                return;
            }

            this.deletingId = post.id;

            // route() даёт только URL — глагол задаёт вызов axios. Ziggy про метод
            // маршрута ничего не сообщает: перепутаете delete с post — получите 405.
            //
            // Сервер вернул 204, res.data будет пустой строкой: обращаться к ней
            // не за чем, поэтому в .then() аргумент не принимаем вовсе.
            axios
                .delete(route('admin.posts.destroy', post.id))
                .then(() => this.reloadAfterDelete())
                .catch((e) => {
                    console.log(e.response?.data);
                })
                // Кнопка должна ожить и после успеха, и после ошибки:
                // страница остаётся на месте в обоих случаях.
                .finally(() => {
                    this.deletingId = null;
                });
        },
        /**
         * Перезапрашивает список после удаления.
         *
         * Локальный filter() по postsData.data выкинул бы строку из таблицы, но всё
         * остальное осталось бы от прежнего ответа: «Всего: 13» вместо 12, пять кнопок
         * страниц вместо четырёх, четыре записи на странице вместо пяти — шестая, которая
         * должна была подняться со следующей страницы, на клиент просто не приезжала.
         * Пересобрать meta на клиенте нельзя: сервер считает total отдельным count(*).
         * Ответ сервера — единственный источник правды.
         */
        reloadAfterDelete() {
            // Удалили единственную запись на не первой странице — этой страницы больше нет,
            // и сервер честно вернёт пустой список с current_page = 3. Отступаем назад;
            // наблюдатель за pagination сам вызовет отложенный запрос, дублировать
            // его руками не нужно.
            //
            // Проверка делается ДО перезапроса: в этот момент удалённый пост ещё лежит
            // в postsData.data, и единица означает «на экране была ровно одна строка,
            // и мы её только что снесли».
            //
            // page > 1: на первой странице отступать некуда — пустой список там законен,
            // и v-if="!postsData.data.length" покажет «Публикаций пока нет».
            if (this.postsData.data.length === 1 && this.entries.pagination.page > 1) {
                this.entries.pagination.page -= 1;

                return;
            }

            // Прямой вызов, а не scheduleFilterPosts(): debounce в 400 мс придуман
            // для ввода символов, на клик по кнопке он даёт только видимую задержку.
            this.filterPosts();
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
