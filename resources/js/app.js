import { createApp, h } from "vue";
import { createInertiaApp, router } from "@inertiajs/vue3";
import menu from "@/Navigation/menu.json";
import MainLayout from "@/Layouts/MainLayout.vue";
import FloatingVue from "floating-vue";
import { autoAnimatePlugin } from "@formkit/auto-animate/vue";
import ContextMenu from "@imengyu/vue3-context-menu";
import "floating-vue/dist/style.css";
import "@imengyu/vue3-context-menu/lib/vue3-context-menu.css";

/**
 * De titel is altijd "Lavoro - <klant> - <onderdeel>".
 *
 * Het onderdeel komt uit menu.json, hetzelfde bestand waar het menu zijn labels
 * uit haalt. Zo staat er in de titelbalk wat er in het menu staat, en levert een
 * nieuw scherm niet een naam op die nergens anders bestaat.
 */
const menuLabels = (() => {
    const labels = [];

    const walk = (items) => (items ?? []).forEach((item) => {
        if (item.href) {
            labels.push({ href: item.href, label: item.label });
        }

        walk(item.items);
    });

    walk(menu.sections ?? Object.values(menu).flat?.() ?? []);

    /** Langste eerst: /serviceorders/12 hoort bij /serviceorders en niet bij /. */
    return labels.sort((a, b) => b.href.length - a.href.length);
})();

const moduleFor = (path) => menuLabels.find(({ href }) => path === href || path.startsWith(href + "/"))?.label;

const applyTitle = () => {
    const base = document.querySelector("title")?.dataset.base
        ?? document.title.split(" - ").slice(0, 2).join(" - ");
    const module = moduleFor(window.location.pathname);

    document.title = [base, module].filter(Boolean).join(" - ");
};

createInertiaApp({
    resolve: async (name) => {
        const pages = import.meta.glob("./Pages/**/*.vue", { eager: true });
        const page = await pages[`./Pages/${name}.vue`];
        page.default.layout = page.default.layout || MainLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        /**
         * De basis ("Lavoro - Klant") komt uit de server-side titel. Die wordt
         * hier vastgelegd voordat er iets aan geplakt wordt, anders groeit hij
         * bij elke navigatie aan.
         */
        const title = document.querySelector("title");

        if (title && !title.dataset.base) {
            title.dataset.base = document.title;
        }

        applyTitle();
        router.on("navigate", applyTitle);

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
