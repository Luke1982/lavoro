<template>
    <span class="inline-flex items-center gap-1 shrink-0">
        <button type="button" @click="openCamera" :disabled="disabled || loading"
            class="inline-flex items-center justify-center size-9 rounded-md border border-lavoro-blue text-lavoro-blue hover:bg-lavoro-blue hover:text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            v-tooltip="'Serienummer scannen met camera'">
            <svg v-if="loading" class="size-5 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4z" />
            </svg>
            <ScanBarcodeIcon v-else class="size-5" />
        </button>
        <button type="button" @click="openGallery" :disabled="disabled || loading"
            class="inline-flex items-center justify-center size-9 rounded-md border border-gray-300 dark:border-slate-600 text-gray-500 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            v-tooltip="'Bestaande foto gebruiken'">
            <ImageIcon class="size-5" />
        </button>
    </span>

    <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden" @change="onFile" />
    <input ref="galleryInput" type="file" accept="image/*" class="hidden" @change="onFile" />

    <ModalDialog :open="resultOpen" @update:open="closeResult" title="Serienummer herkennen"
        max-width-class="sm:max-w-md">
        <div class="flex flex-col gap-3">
            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

            <template v-else-if="candidates.length">
                <p class="text-sm text-gray-500 dark:text-slate-400">
                    Tik op het juiste serienummer.
                </p>
                <div class="flex flex-col gap-2">
                    <button v-for="candidate in candidates" :key="candidate" type="button" @click="pick(candidate)"
                        class="w-full text-left px-3 py-2 rounded-md border border-gray-200 dark:border-slate-600 font-mono text-sm text-gray-900 dark:text-slate-100 hover:border-lavoro-blue hover:bg-lavoro-blue/5 transition-colors">
                        {{ candidate }}
                    </button>
                </div>
            </template>

            <p v-else class="text-sm text-gray-500 dark:text-slate-400">
                Geen serienummer herkend. Probeer een scherpere foto of voer het handmatig in.
            </p>

            <details v-if="rawText" class="text-xs text-gray-400 dark:text-slate-500">
                <summary class="cursor-pointer select-none">Volledige herkende tekst</summary>
                <pre class="mt-2 whitespace-pre-wrap font-mono">{{ rawText }}</pre>
            </details>
        </div>
        <template #footer>
            <div class="flex justify-end">
                <button type="button" @click="closeResult(false)"
                    class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                    Sluiten
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { ScanBarcode as ScanBarcodeIcon, Image as ImageIcon } from '@lucide/vue';
import ModalDialog from '@/Components/UI/ModalDialog.vue';
import { useImageCompression } from '@/Composables/useImageCompression.js';

const emit = defineEmits(['picked']);

defineProps({
    disabled: { type: Boolean, default: false },
});

const { compressImage } = useImageCompression();

const cameraInput = ref(null);
const galleryInput = ref(null);
const loading = ref(false);
const resultOpen = ref(false);
const candidates = ref([]);
const rawText = ref('');
const error = ref('');

function openCamera() {
    cameraInput.value.click();
}

function openGallery() {
    galleryInput.value.click();
}

async function onFile(event) {
    const file = event.target.files[0];
    event.target.value = '';
    if (!file) return;

    loading.value = true;
    error.value = '';
    candidates.value = [];
    rawText.value = '';

    try {
        const compressed = await compressImage(file, 1600, 1600, 0.85);
        await axios.get('/sanctum/csrf-cookie');
        const data = new FormData();
        data.append('image', compressed);
        const response = await axios.post('/api/ocr/serial', data);
        candidates.value = response.data.candidates ?? [];
        rawText.value = response.data.raw ?? '';
    } catch (e) {
        error.value = e.response?.data?.message ?? 'Herkennen is mislukt. Probeer het opnieuw.';
    } finally {
        loading.value = false;
        resultOpen.value = true;
    }
}

function pick(value) {
    emit('picked', value);
    closeResult(false);
}

function closeResult() {
    resultOpen.value = false;
    candidates.value = [];
    rawText.value = '';
    error.value = '';
}
</script>
