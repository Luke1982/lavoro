<template>
    <div
        :class="['@container', border ? 'border-b border-gray-200 dark:border-slate-700/60 pb-3' : '', flush ? '' : 'mb-4']">
        <!-- Actions sit inline by default. `stack-actions` drops them onto their own
             row until the header itself is wide enough, for widgets whose buttons
             are too wide to share a line in a sidebar. -->
        <div
            :class="stackActions ? 'flex flex-col gap-4 @3xl:flex-row @3xl:items-center @3xl:justify-between' : ['flex items-center', $slots.actions ? 'justify-between' : '']">
            <div class="flex items-center min-w-0">
                <div :class="['flex-none flex items-center justify-center size-10 rounded-lavoro-sm mr-3', theme.bg]">
                    <component :is="icon" :class="['size-6 stroke-2', theme.text]" />
                </div>
                <div class="min-w-0">
                    <!-- The badge sits beside the h2, not inside it: text-overflow has no
                         effect on the children of a flex container. -->
                    <div class="flex items-center gap-x-2 min-w-0">
                        <h2 class="text-base font-bold text-gray-900 dark:text-slate-100 truncate">
                            <slot>{{ title }}</slot>
                        </h2>
                        <span v-if="internal"
                            class="flex-none text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded">Intern</span>
                    </div>
                    <p v-if="subtitle" class="mt-0.5 text-sm text-gray-500 dark:text-slate-400">{{ subtitle }}</p>
                </div>
            </div>
            <div v-if="$slots.actions"
                :class="stackActions ? 'flex items-center gap-3 @3xl:flex-none @3xl:flex-wrap' : 'flex-none flex items-center gap-3 ml-3'">
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { CHAPTER_COLORS } from '@/Utilities/chapterColors';

const props = defineProps({
    icon: { required: true },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    /** Key into CHAPTER_COLORS — the preferred way to colour a header. */
    chapter: { type: String, default: '' },
    /** Escape hatch for one-offs that aren't a known chapter type. */
    color: { type: String, default: '' },
    border: { type: Boolean, default: false },
    /** Drops the bottom margin for widgets that pad their own header block. */
    flush: { type: Boolean, default: false },
    /** Marks the section as staff-only, badged next to the title. */
    internal: { type: Boolean, default: false },
    /** Lets wide actions wrap onto their own row in narrow containers. */
    stackActions: { type: Boolean, default: false },
});

const themes = {
    blue: { bg: 'bg-lavoro-blue/10', text: 'text-lavoro-blue' },
    sky: { bg: 'bg-sky-500/10', text: 'text-sky-600 dark:text-sky-400' },
    cyan: { bg: 'bg-cyan-500/10', text: 'text-cyan-600 dark:text-cyan-400' },
    indigo: { bg: 'bg-indigo-500/10', text: 'text-indigo-600 dark:text-indigo-400' },
    violet: { bg: 'bg-violet-600/10', text: 'text-violet-600 dark:text-violet-400' },
    red: { bg: 'bg-red-500/10', text: 'text-red-600 dark:text-red-400' },
    rose: { bg: 'bg-rose-500/10', text: 'text-rose-600 dark:text-rose-400' },
    orange: { bg: 'bg-orange-500/10', text: 'text-orange-600 dark:text-orange-400' },
    amber: { bg: 'bg-amber-500/10', text: 'text-amber-600 dark:text-amber-400' },
    green: { bg: 'bg-green-500/10', text: 'text-green-600 dark:text-green-400' },
    emerald: { bg: 'bg-emerald-500/10', text: 'text-emerald-600 dark:text-emerald-400' },
    teal: { bg: 'bg-teal-500/10', text: 'text-teal-600 dark:text-teal-400' },
    gray: { bg: 'bg-gray-500/10', text: 'text-gray-600 dark:text-slate-300' },
};

const theme = computed(() => themes[props.color || CHAPTER_COLORS[props.chapter]] ?? themes.blue);
</script>
