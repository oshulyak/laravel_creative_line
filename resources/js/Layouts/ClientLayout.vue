<template>
    <div class="min-h-screen bg-gray-100">
        <header class="bg-white shadow">
            <nav class="mx-auto flex max-w-3xl items-center gap-6 px-4 py-4">
                <!--
                    route().current() отвечает, активен ли сейчас маршрут с таким именем.
                    Это Ziggy, а не Inertia: сравнение идёт по имени маршрута, а не по URL,
                    поэтому /feed?page=2 тоже считается лентой.
                -->
                <Link
                    :href="route('client.feed.index')"
                    class="text-sm font-semibold hover:text-sky-700"
                    :class="route().current('client.feed.index') ? 'text-sky-700' : 'text-gray-900'"
                >
                    Лента
                </Link>

                <Link
                    :href="route('client.profiles.personal')"
                    class="text-sm font-semibold hover:text-sky-700"
                    :class="route().current('client.profiles.personal') ? 'text-sky-700' : 'text-gray-900'"
                >
                    Мои публикации
                </Link>

                <!--
                    $page.props.auth.user — общий проп, который кладёт в каждый ответ
                    HandleInertiaRequests::share(). Его не нужно передавать из контроллера:
                    он приезжает на любую Inertia-страницу приложения.
                -->
                <span class="ml-auto text-sm text-gray-500">
                    {{ $page.props.auth.user.name }}
                </span>
            </nav>
        </header>

        <!--
            max-w-3xl вместо табличной ширины админки: лента читается в одну колонку,
            широкая колонка текста читается плохо.
        -->
        <main class="mx-auto max-w-3xl px-4 py-6">
            <slot />
        </main>
    </div>
</template>

<script>
// Именованный импорт: из библиотеки берём только компонент Link.
import { Link } from '@inertiajs/vue3';

export default {
    name: 'ClientLayout',
    components: { Link },
};
</script>
