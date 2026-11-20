<template>
    <ModalDialog :open="open" @update:open="(value) => open = value" max-width-class="sm:max-w-3xl"
        title="Akkoord van de klant vastleggen">
        <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
            De klant tekent hier, op dit scherm. Vink aan welke opties de klant neemt.
        </p>

        <QuoteDocument v-model="form.options" class="mt-4" :lines="lines" :totals="totals" pickable />
        <p v-if="form.errors.options" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.options }}</p>

        <div class="mt-6 space-y-4">
            <div>
                <span class="text-xs font-bold text-slate-400">Naam van de klant</span>
                <TextInput v-model="form.name" :has-error="Boolean(form.errors.name)" :error-message="form.errors.name" />
            </div>
            <div>
                <span class="text-xs font-bold text-slate-400">Handtekening van de klant</span>
                <SignaturePad ref="pad" v-model="form.signature" class="mt-1" />
                <p v-if="form.errors.signature" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.signature }}</p>
            </div>
            <label v-if="canCreateServiceOrder" class="flex items-center gap-2 text-sm">
                <SwitchComponent v-model="form.make_service_order" />
                Direct een werkbon maken
            </label>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <button type="button" @click="open = false"
                class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                Annuleren
            </button>
            <button type="button" @click="submit" :disabled="form.processing"
                class="px-4 py-2 text-sm font-medium bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-60">
                Akkoord vastleggen
            </button>
        </div>
    </ModalDialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import SignaturePad from '@/Components/UI/SignaturePad.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import QuoteDocument from '@/Components/Quotes/QuoteDocument.vue'

/**
 * The customer says yes in front of whoever made the quote, and signs on the
 * screen. The werkbon can follow in the same step; then the page goes there.
 */
const props = defineProps({
    quoteId: { type: Number, required: true },
    lines: { type: Array, required: true },
    totals: { type: Object, required: true },
    defaultName: { type: String, default: '' },
    canCreateServiceOrder: { type: Boolean, default: false },
})

const open = defineModel({ type: Boolean, default: false })
const pad = ref(null)

const form = useForm({ name: '', signature: '', options: [], make_service_order: true })

watch(open, (opened) => {
    if (!opened) return
    form.reset()
    form.clearErrors()
    form.name = props.defaultName
    form.make_service_order = props.canCreateServiceOrder
}, { immediate: true })

/** The signature as drawn, also when nobody pressed save under it. */
function submit() {
    if (pad.value && !pad.value.isEmpty()) {
        form.signature = pad.value.getDataUrl()
    }
    form.post(`/quotes/${props.quoteId}/accept`, { preserveScroll: true, onSuccess: () => { open.value = false } })
}
</script>
