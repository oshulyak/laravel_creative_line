<template>
    <Head title="Dashboard" />

    <h3 class="mb-4 text-2xl font-semibold text-gray-900">Dashboard</h3>

    <div class="bg-white p-4 text-sm text-gray-500">
        Сводная статистика появится здесь позже.
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
};
</script>
