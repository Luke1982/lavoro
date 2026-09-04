import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import LandlordLayout from "@/Layouts/LandlordLayout.vue";

/**
 * Het beheerpaneel is een eigen Inertia-app, los van die van de klant.
 *
 * Los, omdat het op de centrale database draait en nooit een tenant heeft: de
 * gedeelde gegevens, het menu en de rechten van de klant-app slaan hier
 * nergens op, en meeliften zou betekenen dat elk scherm daar rekening mee moet
 * houden of er wel een klant is.
 */
createInertiaApp({
    title: (title) => (title ? `Lavoro Beheer - ${title}` : "Lavoro Beheer"),
    resolve: async (name) => {
        const pages = import.meta.glob("./Pages/Landlord/**/*.vue", { eager: true });
        const page = await pages[`./Pages/${name}.vue`];

        page.default.layout = page.default.layout || LandlordLayout;

        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: { color: "#1d4ed8" },
});
