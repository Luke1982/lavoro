import globals from "globals";
import pluginJs from "@eslint/js";
import pluginVue from "eslint-plugin-vue";
import tseslint from "typescript-eslint";

export default [
    {
        files: ["**/*.{js,mjs,cjs,vue}"],
    },
    {
        languageOptions: {
            globals: globals.browser,
        },
    },
    pluginJs.configs.recommended,
    ...pluginVue.configs["flat/essential"],
    {
        files: ["**/*.vue"],
        languageOptions: {
            parserOptions: {
                parser: tseslint.parser,
                extraFileExtensions: [".vue"],
            },
        },
    },
    {
        /**
         * A template calling something that does not exist fails silently: Vue
         * binds undefined, the click does nothing, and nothing anywhere says so.
         * A history panel shipped that way and so did the arrow keys before it,
         * both reported as working because they rendered perfectly.
         *
         * Scoped rather than switched on everywhere. Six other components trip it
         * today, and turning those into errors is somebody else's decision to
         * make about their own files, not a side effect of fixing this one.
         */
        files: [
            "resources/js/Components/Assistant/**/*.vue",
            "resources/js/Components/UI/SpotlightShell.vue",
            "resources/js/Components/UI/MarkdownText.vue",
        ],
        rules: {
            "vue/no-undef-properties": "error",
        },
    },
];
