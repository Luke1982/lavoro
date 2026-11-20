<template>
    <ModalDialog :open="open" @update:open="(value) => open = value" title="Offerte afgewezen">
        <p class="text-sm text-gray-500 dark:text-slate-400">
            Waarom ging het niet door? Met een reden zie je later in de lijst waarom offertes verloren gaan.
        </p>
        <div class="mt-4 space-y-4">
            <div>
                <span class="text-xs font-bold text-slate-400">Reden</span>
                <ComboBox :options="reasons" v-model="form.lost_reason_id" placeholder="Geen reden"
                    :has-error="Boolean(form.errors.lost_reason_id)" :error-message="form.errors.lost_reason_id" />
            </div>
            <div>
                <span class="text-xs font-bold text-slate-400">Toelichting</span>
                <textarea v-model="form.lost_reason_note" rows="3" placeholder="Bijvoorbeeld wat de klant zei"
                    class="mt-1 block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 text-sm p-2" />
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="open = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submit" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-60">
                    Afgewezen
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import ComboBox from '@/Components/UI/ComboBox.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'

/** A quote marked as lost by a colleague, with why, when they know it. */
const props = defineProps({
    quoteId: { type: Number, required: true },
    reasons: { type: Array, default: () => [] },
})

const open = defineModel({ type: Boolean, default: false })

const form = useForm({ status: 'Afgewezen', lost_reason_id: null, lost_reason_note: '' })

function submit() {
    form.transform(data => ({ ...data, lost_reason_note: data.lost_reason_note || null }))
        .patch(`/quotes/${props.quoteId}/status`, { preserveScroll: true, onSuccess: () => { open.value = false; form.reset() } })
}
</script>
