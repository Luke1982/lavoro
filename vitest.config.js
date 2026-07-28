import { fileURLToPath } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        globals: true,
        /**
         * Only our own tests. Without this the default glob reaches into
         * vendor/, where composer packages ship their own suites written against
         * dependencies we do not install — anthropic-ai/sdk brought one in, and
         * it failed the run while having nothing to do with this application.
         */
        include: ['resources/js/**/*.{test,spec}.{js,ts}'],
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
})
