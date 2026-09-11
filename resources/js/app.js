import { createApp, h } from "vue";
import { createInertiaApp, router } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";
import FloatingVue from "floating-vue";
import { autoAnimatePlugin } from "@formkit/auto-animate/vue";
import ContextMenu from "@imengyu/vue3-context-menu";
import "floating-vue/dist/style.css";
import "@imengyu/vue3-context-menu/lib/vue3-context-menu.css";

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
