import { createApp, h } from "vue";
import { createInertiaApp, router } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";
import FloatingVue from "floating-vue";
import { autoAnimatePlugin } from "@formkit/auto-animate/vue";
import ContextMenu from "@imengyu/vue3-context-menu";
import "floating-vue/dist/style.css";
import "@imengyu/vue3-context-menu/lib/vue3-context-menu.css";
import axios from "axios";

/**
 * Een verlopen sessie: de server weigert een opslag met 419, omdat het
 * CSRF-token bij de oude sessie hoort. Met "Onthoud mij" ben je bij die
 * weigering al opnieuw aangemeld, dus met een vers token lukt hetzelfde verzoek
 * wel. Eén keer opnieuw; lukt dat ook niet, dan krijgt het scherm de fout.
 */
axios.interceptors.response.use(undefined, async (error) => {
    const config = error.config;

    if (error.response?.status !== 419 || !config || config.retriedAfterExpiredSession) {
        return Promise.reject(error);
    }

    config.retriedAfterExpiredSession = true;
    await axios.get("/sanctum/csrf-cookie");

    return axios(config);
});

/**
 * De titel ("Lavoro - <onderdeel> - <klant>") maakt de server, in
 * App\Support\PageTitle, en stuurt hem met elke pagina mee.
 *
 * Inertia gooit bij het opstarten de titel uit de server weg en vraagt de
 * title-callback om een nieuwe; zonder <Head> op de pagina krijgt die een
 * lege string. Gaf de callback die gewoon terug, dan stond er na elke volledige
 * paginalading niets in het tabblad.
 */
let currentTitle = document.title;

const applyTitle = (page) => {
    currentTitle = page?.props?.title ?? currentTitle;
    document.title = currentTitle;
};

createInertiaApp({
    title: (title) => title || currentTitle,
    resolve: async (name) => {
        const pages = import.meta.glob("./Pages/**/*.vue", { eager: true });
        const page = await pages[`./Pages/${name}.vue`];
        page.default.layout = page.default.layout || MainLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        applyTitle(props.initialPage);
        router.on("navigate", (event) => applyTitle(event.detail.page));

        createApp({
            render: () => h(App, props),
        })
            .use(plugin)
            .use(FloatingVue)
            .use(autoAnimatePlugin)
            .use(ContextMenu)
            .mount(el);
    },
});
