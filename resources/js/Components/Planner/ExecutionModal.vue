<template>
    <ModalDialog :open="open" title="Tijden en handtekening" center @update:open="$emit('update:open', $event)">
        <div class="space-y-4">
            <slot />
            <div v-if="signature">
                <p class="text-xs text-gray-500 dark:text-slate-400 mb-1">Huidige handtekening</p>
                <img :src="signature" alt="Handtekening"
                    class="max-h-28 w-auto rounded-md border border-gray-200 dark:border-slate-600 bg-white" />
                <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Teken hieronder om te vervangen.</p>
            </div>
            <SignaturePad v-model="signature" />
        </div>
        <template #footer>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button type="button"
                    class="rounded-md border border-gray-200 dark:border-slate-600 px-4 py-2 text-sm text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/50"
                    @click="$emit('update:open', false)">
                    Annuleren
                </button>
                <button type="button" :disabled="!signature || busy"
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-lavoro-green px-4 py-2 text-sm font-semibold text-gray-900 disabled:opacity-40"
                    @click="$emit('confirm', signature)">
                    <SaveAll class="size-4 shrink-0" />
                    Opslaan
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import SignaturePad from '@/Components/UI/SignaturePad.vue'
import { SaveAll } from '@lucide/vue'

const props = defineProps({
    open: { type: Boolean, required: true },
    initialSignature: { type: String, default: '' },
    busy: { type: Boolean, default: false },
})

defineEmits(['update:open', 'confirm'])

const signature = ref(props.initialSignature)

watch(() => props.open, (isOpen) => {
    if (isOpen) signature.value = props.initialSignature
})
</script>
