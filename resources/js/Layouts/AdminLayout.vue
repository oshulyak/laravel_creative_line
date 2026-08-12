<template>
    <div>
        <div class="w-full bg-white p-4 shadow">
            <header class="text-sm font-semibold uppercase tracking-wider text-gray-700">
                Admin panel
            </header>
        </div>

        <div class="flex">
            <nav class="min-h-screen w-1/4 bg-sky-700">
                <Link
                    :href="route('admin.dashboard.index')"
                    class="block border-b border-sky-900 p-4 text-xs text-gray-200 hover:bg-sky-800"
                >
                    Dashboard
                </Link>

                <Link
                    :href="route('admin.posts.index')"
                    class="block border-b border-sky-900 p-4 text-xs text-gray-200 hover:bg-sky-800"
                >
                    Posts
                </Link>
            </nav>

            <article class="w-3/4 bg-gray-100 p-4">
                <slot />
            </article>
        </div>
    </div>
</template>

<script>
// Именованный импорт: из библиотеки берём только компонент Link.
import { Link } from '@inertiajs/vue3';

export default {
    name: 'AdminLayout',
    // components — зарезервированный ключ Options API: компонент нужно
    // зарегистрировать, чтобы использовать его как тег <Link>.
    components: { Link },
};
</script>
