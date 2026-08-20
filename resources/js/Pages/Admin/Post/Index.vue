<template>
    <Head title="Posts" />

    <header class="mb-6 flex items-baseline justify-between">
        <h3 class="text-2xl font-semibold text-gray-900">Posts</h3>
        <span class="text-sm text-gray-500">Всего: {{ posts.length }}</span>
    </header>

    <Link
        :href="route('admin.posts.create')"
        class="mb-4 inline-block border border-sky-800 bg-sky-700 px-3 py-2 text-xs text-white hover:bg-sky-800"
    >
        Создать
    </Link>

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
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    <tr
                        v-for="post in posts"
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
                    </tr>

                    <tr v-if="!posts.length">
                        <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                            Публикаций пока нет.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script>
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
    methods: {
        excerpt(text, limit = 140) {
            if (!text) {
                return '';
            }

            return text.length > limit ? `${text.slice(0, limit).trimEnd()}…` : text;
        },
        formatDate(value) {
            if (!value) {
                return null;
            }

            // '2026-06-28 11:20:01' → ISO-подобный вид, который разбирают все браузеры.
            return new Date(value.replace(' ', 'T')).toLocaleString('ru-RU', {
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
