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
                    client.chats.* — звёздочка в Ziggy: пункт подсвечен и на списке
                    чатов (client.chats.index), и внутри любого чата (client.chats.show).
                -->
                <Link
                    :href="route('client.chats.index')"
                    class="text-sm font-semibold hover:text-sky-700"
                    :class="route().current('client.chats.*') ? 'text-sky-700' : 'text-gray-900'"
                >
                    Чаты
                </Link>

                <!--
                    Страница темы — client.themes.show, но это тоже раздел групп:
                    подсвечиваем пункт и там.
                -->
                <Link
                    :href="route('client.groups.index')"
                    class="text-sm font-semibold hover:text-sky-700"
                    :class="route().current('client.groups.*') || route().current('client.themes.*') ? 'text-sky-700' : 'text-gray-900'"
                >
                    Группы
                </Link>

                <!--
                    relative на обёртке — точка отсчёта для absolute-позиционирования
                    и счётчика, и выпадающего списка. Без неё попап уедет
                    относительно всей страницы.
                -->
                <div class="relative ml-auto flex items-center gap-4">
                    <button
                        type="button"
                        class="relative text-gray-500 hover:text-sky-700"
                        @click="toggleNotifications"
                    >
                        <svg
                            class="h-6 w-6"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"
                            />
                        </svg>

                        <!--
                            v-if, а не текст «0»: пустой колокольчик не должен
                            кричать нулём.
                        -->
                        <span
                            v-if="unreadCount > 0"
                            class="absolute -right-2 -top-2 rounded-full bg-red-600 px-1.5 text-xs font-semibold text-white"
                        >
                            {{ unreadCount }}
                        </span>
                    </button>

                    <!--
                        $page.props.auth.user — общий проп, который кладёт в каждый ответ
                        HandleInertiaRequests::share(). Его не нужно передавать из контроллера:
                        он приезжает на любую Inertia-страницу приложения.
                    -->
                    <span class="text-sm text-gray-500">
                        {{ $page.props.auth.user.name }}
                    </span>

                    <div
                        v-if="isPopupShown"
                        class="absolute right-0 top-10 z-10 w-80 rounded-lg border border-gray-200 bg-white p-3 shadow-lg"
                    >
                        <p v-if="isLoading" class="py-2 text-sm text-gray-500">Загружаю…</p>

                        <p v-else-if="!notifications.length" class="py-2 text-sm text-gray-500">
                            Уведомлений пока нет.
                        </p>

                        <ul v-else class="divide-y divide-gray-100">
                            <li v-for="notification in notifications" :key="notification.id" class="py-2">
                                <!--
                                    Link, а не <a>: переход внутри приложения должен
                                    остаться SPA-переходом. Попап закрываем сами —
                                    раскладка при переходе не пересоздаётся, и открытым
                                    он бы так и остался поверх новой страницы.
                                -->
                                <Link
                                    v-if="notification.url"
                                    :href="notification.url"
                                    class="block text-sm hover:text-sky-700"
                                    :class="notification.is_read ? 'text-gray-500' : 'font-medium text-gray-900'"
                                    @click="isPopupShown = false"
                                >
                                    {{ notification.body }}
                                </Link>

                                <span v-else class="block text-sm text-gray-500">
                                    {{ notification.body }}
                                </span>
                            </li>
                        </ul>

                        <button
                            type="button"
                            class="mt-2 w-full border-t border-gray-200 pt-2 text-center text-sm text-gray-500 hover:text-gray-900"
                            @click="isPopupShown = false"
                        >
                            Закрыть
                        </button>
                    </div>
                </div>
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
import axios from 'axios';
// Именованный импорт: из библиотеки берём только то, что нужно.
import { Link, router } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';

export default {
    name: 'ClientLayout',
    components: { Link },
    data() {
        return {
            isPopupShown: false,
            isLoading: false,
            notifications: [],
            // Имя канала уведомлений запоминаем при создании раскладки.
            // К моменту beforeUnmount() общие пропсы уже принадлежат новой
            // странице: например, после выхода auth.user станет null,
            // и вычислить имя канала заново не получится.
            notificationsChannel: null,
        };
    },
    computed: {
        // Счётчик берём из общих пропсов. Optional chaining обязателен:
        // у пользователя может не быть профиля, и тогда profile === null.
        unreadCount() {
            return this.$page.props.auth.user.profile?.notifications_count ?? 0;
        },
    },
    /**
     * Подписка на новые уведомления текущего профиля.
     *
     * Подписка в раскладке, а не на странице: колокольчик есть на каждой
     * странице клиентской части, а раскладка переживает переходы между ними.
     * Значит, одна подписка работает всё время, пока пользователь здесь.
     */
    created() {
        const profileId = this.$page.props.auth.user.profile?.id;

        // Без профиля уведомлений не бывает: слушать нечего.
        if (!profileId) {
            return;
        }

        this.notificationsChannel = `profiles.${profileId}.notifications`;

        echo()
            .private(this.notificationsChannel)
            .listen('.notification.created', (e) => {
                // Число уже посчитано на сервере. replaceProp() подставляет его
                // в общий проп без запроса, и unreadCount пересчитается сам.
                //
                // Не router.reload(): число уже пришло в событии, а reload
                // отправил бы запрос, и контроллер текущей страницы (лента, чат)
                // заново выполнил бы все свои запросы к базе.
                router.replaceProp('auth.user.profile.notifications_count', e.notifications_count);
            });
    },
    /**
     * Отписка, когда раскладка исчезает: пользователь вышел или перешёл
     * на страницу с другой раскладкой.
     */
    beforeUnmount() {
        if (this.notificationsChannel) {
            echo().leave(this.notificationsChannel);
        }
    },
    methods: {
        // Клик по колокольчику: закрыть, если открыт; открыть и загрузить, если закрыт.
        toggleNotifications() {
            if (this.isPopupShown) {
                this.isPopupShown = false;

                return;
            }

            this.isPopupShown = true;
            this.loadNotifications();
        },

        // Данные грузим КАЖДЫЙ раз при открытии, а не один раз за сессию страницы:
        // раскладка живёт до перезагрузки браузера, и закэшированный список
        // устарел бы уже через минуту.
        loadNotifications() {
            this.isLoading = true;

            axios
                .get(route('client.profiles.notifications.index'))
                .then((response) => {
                    this.notifications = response.data;

                    // Показанные уведомления стали прочитанными (это сделал
                    // NotificationObserver на выборке), но число в шапке приехало
                    // со страницей и об этом не знает. Частичная перезагрузка просит
                    // Inertia обновить ТОЛЬКО проп auth: страница не перерисовывается,
                    // запрос уходит один и маленький.
                    //
                    // Счётчик при этом не обязан стать нулём: показываем двадцать,
                    // и если непрочитанных было больше, остаток останется на месте.
                    router.reload({ only: ['auth'] });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
    },
};
</script>
