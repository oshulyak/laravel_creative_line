import '../css/app.css';

import axios from 'axios';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { configureEcho } from '@laravel/echo-vue';

// Echo добавляет заголовок X-Socket-ID только к ГЛОБАЛЬНОМУ axios — window.axios.
// По этому заголовку toOthers() на сервере узнаёт, какой вкладке событие
// не отправлять. Компоненты импортируют тот же самый экземпляр axios,
// поэтому заголовок получат и их запросы.
//
// Строка стоит до configureEcho(): заголовок подключается в момент создания
// Echo, и глобальный axios к этому времени уже должен существовать.
window.axios = axios;

// Ключи и адрес сервера configureEcho() берёт из VITE_REVERB_* сам.
// Соединение откроется при первом вызове echo() в компоненте.
configureEcho({
    broadcaster: 'reverb',
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
