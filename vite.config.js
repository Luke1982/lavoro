import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import tailwindcss from "@tailwindcss/vite";
import vue from "@vitejs/plugin-vue";
import { execSync } from "child_process";
import { readFileSync, writeFileSync } from "fs";

function swGitHash() {
    return {
        name: "sw-git-hash",
        closeBundle() {
            const hash = execSync("git rev-parse --short HEAD").toString().trim();
            const path = "public/service-worker.js";
            const updated = readFileSync(path, "utf-8").replace(
                /const CACHE_NAME = "[^"]+";/,
                `const CACHE_NAME = "lavoro-cache-${hash}";`
            );
            writeFileSync(path, updated);
        },
    };
}

export default defineConfig({
    publicDir: 'public',
    optimizeDeps: {
        exclude: ['@capacitor-community/background-geolocation'],
    },
    build: {
        rollupOptions: {
            external: ['@capacitor-community/background-geolocation'],
        },
    },
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.js"],
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
        swGitHash(),
    ],
});
