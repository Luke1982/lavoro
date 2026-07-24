<template>
    <ModalDialog :open="open" @update:open="$emit('update:open', false)" max-width-class="sm:max-w-7xl">
        <div class="flex min-h-0 flex-col" style="max-height: 78vh">
            <div class="flex flex-wrap items-start justify-between gap-3 pr-8">
                <div class="min-w-0">
                    <p class="truncate text-base font-semibold text-gray-900 dark:text-white">{{ title }}</p>
                    <p v-if="truncated" class="mt-0.5 text-xs text-amber-600 dark:text-amber-400">
                        Alleen de eerste {{ MAX_ROWS }} rijen worden getoond.
                    </p>
                </div>
                <div class="flex flex-none items-center gap-2">
                    <a v-if="url" :href="url" download
                        class="inline-flex items-center gap-1.5 rounded-lavoro-sm border border-gray-200 dark:border-slate-700 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                        <ArrowDownTrayIcon class="size-4" />
                        Downloaden
                    </a>
                    <!-- ModalDialog only renders its own close button on mobile, and
                         the grid swallows Escape, so this modal needs its own. -->
                    <button type="button" @click="$emit('update:open', false)" aria-label="Sluiten"
                        class="inline-flex size-9 items-center justify-center rounded-lavoro-sm border border-gray-200 dark:border-slate-700 text-gray-500 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700 cursor-pointer">
                        <XMarkIcon class="size-5" />
                    </button>
                </div>
            </div>

            <div v-if="sheets.length > 1" class="mt-4 flex flex-wrap gap-2">
                <button v-for="(sheet, index) in sheets" :key="sheet.name" type="button" @click="activeIndex = index"
                    :class="[
                        'rounded-lavoro-sm px-3 py-1.5 text-sm font-medium transition-colors cursor-pointer',
                        activeIndex === index
                            ? 'bg-lavoro-lightblue text-lavoro-blue dark:bg-lavoro-blue/20 dark:text-blue-300'
                            : 'border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800'
                    ]">
                    {{ sheet.name }}
                </button>
            </div>

            <div class="mt-4 min-h-0 flex-1 overflow-auto">
                <div v-if="loading" class="flex h-48 items-center justify-center">
                    <div class="size-8 animate-spin rounded-full border-b-2 border-lavoro-blue"></div>
                </div>

                <div v-else-if="error" class="flex h-48 flex-col items-center justify-center gap-3 text-sm">
                    <p class="text-gray-500 dark:text-slate-400">{{ error }}</p>
                    <a v-if="url" :href="url" download class="font-medium text-lavoro-blue hover:underline">
                        Probeer te downloaden
                    </a>
                </div>

                <p v-else-if="activeSheet && activeSheet.data.length === 0"
                    class="py-12 text-center text-sm text-gray-500 dark:text-slate-400">
                    Dit werkblad is leeg.
                </p>

                <SpreadsheetComponent v-else-if="activeSheet" :key="`${activeIndex}-${title}`"
                    :model-value="sheetModel" :min-dimensions="activeSheet.dimensions" readonly :toolbar="false" />
            </div>
        </div>
    </ModalDialog>
</template>

<script setup>
import { computed, ref, shallowRef, watch } from 'vue';
import { ArrowDownTrayIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import ModalDialog from '@/Components/UI/ModalDialog.vue';
import SpreadsheetComponent from '@/Components/UI/SpreadsheetComponent.vue';

/**
 * Renders a workbook in jspreadsheet. jspreadsheet-ce reads no binary format of
 * its own, so SheetJS decodes the bytes into rows first — behind a dynamic
 * import, since it is far too heavy to carry on every page load.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    url: { type: String, default: '' },
    title: { type: String, default: '' },
});

defineEmits(['update:open']);

// A viewer, not an editor: the cap keeps a runaway export from locking up the
// tab, and the header says so rather than silently showing a partial sheet.
const MAX_ROWS = 2000;

const loading = ref(false);
const error = ref('');
// shallowRef, not ref: a deep ref hands jspreadsheet reactive Proxies, and
// SpreadsheetComponent structuredClones the rows — which throws on a Proxy.
// Thousands of cells have no business being reactive either.
const sheets = shallowRef([]);
const truncated = ref(false);
const activeIndex = ref(0);

const activeSheet = computed(() => sheets.value[activeIndex.value] ?? null);

// Stable identity so the sheet component's deep watcher doesn't re-diff every
// cell on each unrelated re-render of the parent.
const sheetModel = computed(() => ({ data: activeSheet.value?.data ?? [] }));

function reset() {
    loading.value = false;
    error.value = '';
    sheets.value = [];
    truncated.value = false;
    activeIndex.value = 0;
}

async function load() {
    reset();
    loading.value = true;

    try {
        const [{ read, utils }, response] = await Promise.all([
            import('xlsx'),
            fetch(props.url, { headers: { Accept: '*/*' } }),
        ]);

        if (!response.ok) throw new Error('download');

        const workbook = read(new Uint8Array(await response.arrayBuffer()), { type: 'array', cellDates: true });

        sheets.value = workbook.SheetNames.map((name) => {
            const rows = utils.sheet_to_json(workbook.Sheets[name], {
                header: 1,
                raw: false,
                defval: '',
                blankrows: false,
            });

            if (rows.length > MAX_ROWS) {
                truncated.value = true;
            }

            const data = rows.slice(0, MAX_ROWS).map((row) => row.map((cell) => (cell === null ? '' : String(cell))));
            const widest = data.reduce((max, row) => Math.max(max, row.length), 0);

            return { name, data, dimensions: [Math.max(widest, 1), Math.max(data.length, 1)] };
        });
    } catch {
        error.value = 'Dit bestand kon niet worden gelezen.';
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (isOpen) => (isOpen && props.url ? load() : reset()));
</script>
