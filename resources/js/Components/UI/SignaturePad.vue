<template>
    <div class="border border-gray-200 dark:border-slate-600 rounded-lg overflow-hidden">
        <div class="m-3 border-2 border-dashed border-gray-200 dark:border-slate-600 rounded-lg relative overflow-hidden" @pointerdown="onStrokeBegin">
            <div
                v-show="!hasStrokes"
                class="absolute inset-0 flex flex-col items-center justify-center text-gray-400 dark:text-slate-500 pointer-events-none z-10"
            >
                <PenLine class="w-12 h-12 mb-3 text-gray-300 dark:text-slate-600" />
                <p class="font-semibold text-sm">Klik of teken in het vak</p>
                <p class="text-xs mt-1 text-gray-300 dark:text-slate-600">De handtekening wordt hier weergegeven.</p>
            </div>
            <VueSignaturePad
                ref="signature"
                height="200px"
                :maxWidth="2"
                :minWidth="2"
                :disabled="readonly"
                :options="{
                    penColor: 'rgb(0, 0, 0)',
                    backgroundColor: 'rgb(255, 255, 255)',
                }"
            />
        </div>
        <div v-if="!readonly" class="flex flex-col sm:flex-row border-t border-gray-200 dark:border-slate-600 divide-y divide-gray-200 dark:divide-slate-600 sm:divide-y-0 sm:divide-x">
            <button
                type="button"
                class="flex-1 py-3 flex items-center justify-center gap-2 text-sm text-gray-600 dark:text-slate-300 cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-700/50"
                @click="handleClear"
            >
                <Eraser class="w-4 h-4 shrink-0" />
                Wissen
            </button>
            <button
                type="button"
                :disabled="!hasStrokes"
                :class="['flex-1 py-3 flex items-center justify-center gap-2 text-sm', hasStrokes ? 'text-gray-600 dark:text-slate-300 cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-700/50' : 'text-gray-300 dark:text-slate-600 cursor-not-allowed']"
                @click="handleSave"
            >
                <Save class="w-4 h-4 shrink-0" />
                Handtekening opslaan
            </button>
            <button
                type="button"
                class="flex-1 py-3 flex items-center justify-center gap-2 text-sm text-gray-600 dark:text-slate-300 cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-700/50"
                @click="handleUndo"
            >
                <RotateCcw class="w-4 h-4 shrink-0" />
                Ongedaan maken
            </button>
        </div>
    </div>
</template>

<script setup>
import { VueSignaturePad } from "@selemondev/vue3-signature-pad"
import { ref } from "vue";
import { Eraser, RotateCcw, PenLine, Save } from '@lucide/vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    readonly: { type: Boolean, default: false }
});

const emit = defineEmits(['update:modelValue']);

const signature = ref();
const hasStrokes = ref(false);

const onStrokeBegin = () => {
    if (!props.readonly) hasStrokes.value = true;
};

const handleSave = () => {
    emit('update:modelValue', signature.value.saveSignature('image/jpeg'));
};

const handleClear = () => {
    signature.value.clearCanvas();
    hasStrokes.value = false;
};

const handleUndo = () => {
    signature.value.undo();
    hasStrokes.value = !signature.value.isEmpty();
};
</script>
