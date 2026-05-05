import './bootstrap'

import {createApp, h} from 'vue'
import {createInertiaApp} from '@inertiajs/vue3'
import {resolvePageComponent} from 'laravel-vite-plugin/inertia-helpers'
import PrimeVue from 'primevue/config';
import ToastService from 'primevue/toastservice';

createInertiaApp({
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({el, App, props, plugin}) {
        createApp({render: () => h(App, props)})
            .use(plugin)
            .use(PrimeVue, {
                unstyled: true,
            })
            .use(ToastService)
            .mount(el)
    },
})
