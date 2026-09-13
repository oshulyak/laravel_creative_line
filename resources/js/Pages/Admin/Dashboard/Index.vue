<template>
    <Head title="Dashboard" />

    <header class="mb-6 flex items-baseline justify-between">
        <h3 class="text-2xl font-semibold text-gray-900">Dashboard</h3>
        <span class="text-sm text-gray-500">Статистика за последние 30 дней</span>
    </header>

    <div class="overflow-hidden rounded-lg bg-white shadow">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium">Дата</th>
                        <th class="px-4 py-3 text-right font-medium">Посты</th>
                        <th class="px-4 py-3 text-right font-medium">Репосты</th>
                        <th class="px-4 py-3 text-right font-medium">Комментарии</th>
                        <th class="px-4 py-3 text-right font-medium">Лайки</th>
                        <th class="px-4 py-3 text-right font-medium">Просмотры</th>
                        <th class="px-4 py-3 text-right font-medium">Лайки / просмотры</th>
                        <th class="px-4 py-3 text-right font-medium">Лайки / комменты</th>
                    </tr>
                </thead>

                <!--
                    Числа выравниваем вправо и моноширинным шрифтом: разряды встают
                    друг под другом, и рост по дням читается сверху вниз без усилий.
                -->
                <tbody class="divide-y divide-gray-100 font-mono">
                    <tr
                        v-for="statistic in statistics"
                        :key="statistic.id"
                        class="transition-colors hover:bg-gray-50"
                    >
                        <td class="whitespace-nowrap px-4 py-4 text-gray-900">
                            {{ formatDate(statistic.date) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 text-right text-gray-700">
                            {{ statistic.posts_count }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 text-right text-gray-700">
                            {{ statistic.reposts_count }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 text-right text-gray-700">
                            {{ statistic.comments_count }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 text-right text-gray-700">
                            {{ statistic.likes_count }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 text-right text-gray-700">
                            {{ statistic.views_count }}
                        </td>
                        <!--
                            ?? '—' — отношение приходит null, когда делить не на что
                            (ноль просмотров или ноль комментариев). Ноль тут был бы
                            неправдой: «лайков на просмотр 0» и «не посчитать» — разные вещи.
                        -->
                        <td class="whitespace-nowrap px-4 py-4 text-right text-gray-700">
                            {{ statistic.likes_to_views_ratio ?? '—' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 text-right text-gray-700">
                            {{ statistic.likes_to_comments_ratio ?? '—' }}
                        </td>
                    </tr>

                    <tr v-if="!statistics.length">
                        <td colspan="8" class="px-4 py-10 text-center font-sans text-gray-500">
                            Статистики пока нет. Она собирается каждую ночь в 02:56 (UTC),
                            вручную — командой php artisan statistics:aggregate.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
    name: 'Index',
    // layout — свойство Inertia, а не Vue: при SPA-переходе layout не пересоздаётся,
    // меняется только содержимое его <slot />.
    layout: AdminLayout,
    components: { Head },
    props: {
        // Массив строк из StatisticResource, уже отсортированный сервером: новые сверху.
        statistics: {
            type: Array,
            required: true,
        },
    },
    methods: {
        formatDate(value) {
            // '2026-09-13' → '13.09.2026' простой перестановкой частей, без new Date():
            // строка без времени разбирается как полночь по UTC, и в браузере
            // западнее Гринвича дата уехала бы на день назад.
            return value.split('-').reverse().join('.');
        },
    },
};
</script>
