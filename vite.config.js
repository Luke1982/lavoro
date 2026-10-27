import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import tailwindcss from "@tailwindcss/vite";
import vue from "@vitejs/plugin-vue";
import { execSync } from "child_process";
import { readFileSync, writeFileSync } from "fs";

function nativeOnly(modules) {
    return {
        name: "native-only-modules",
        resolveId(id) {
            if (modules.includes(id)) return { id, external: true };
        },
    };
}

/**
 * Zet de huidige commit in de naam van de cache, zodat browsers na een deploy
 * hun oude bestanden weggooien.
 *
 * De bron staat in resources/ en het resultaat in public/, net als de rest van
 * wat de build maakt. Eerder werd het bestand in public/ ter plekke aangepast --
 * een bestand dat in git zit -- en dan is de werkmap na elke build vies en
 * breekt de eerstvolgende git pull op "local changes would be overwritten".
 */
function swGitHash() {
    return {
        name: "sw-git-hash",
        closeBundle() {
            const hash = execSync("git rev-parse --short HEAD").toString().trim();

            const source = readFileSync("resources/service-worker.js", "utf-8").replace(
                /const CACHE_NAME = "[^"]+";/,
                `const CACHE_NAME = "lavoro-cache-${hash}";`
            );

            writeFileSync("public/service-worker.js", source);
        },
    };
}

export default defineConfig({
    publicDir: 'public',
    plugins: [
        laravel({
            input: [
                "resources/css/app.css",
                "resources/js/app.js",
                // Het beheerpaneel is een eigen Inertia-app op de centrale
                // database; het deelt geen gedeelde gegevens met de klant-app.
                "resources/js/landlord.js",
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
        nativeOnly([]),
        swGitHash(),
    ],
});
