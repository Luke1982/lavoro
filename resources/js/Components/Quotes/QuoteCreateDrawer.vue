<template>
    <DrawerComponent v-model="open" :title="origin ? `Offerte vanuit ${start?.label ?? '…'}` : 'Nieuwe offerte'"
        subtitle="De offerte krijgt bij het opslaan vanzelf een nummer en is een maand geldig.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <DrawerField label="Titel">
                <TextInput v-model="form.title" type="text" placeholder="Bijvoorbeeld: Airco woonkamer en slaapkamer"
                    :hasError="Boolean(form.errors.title)" :errorMessage="form.errors.title" />
            </DrawerField>
            <DrawerField label="Klant">
                <p v-if="origin" class="py-2 text-sm">{{ start?.customer?.name ?? 'Geen klant gevonden' }}</p>
                <ComboBox v-else :options="customerOptions" v-model="form.customer_id" placeholder="Selecteer klant"
                    :has-external-searching="customersUseAjax" :searching="customersSearching"
                    @change="searchCustomers"
                    :hasError="Boolean(form.errors.customer_id)" :errorMessage="form.errors.customer_id" />
                <p v-if="origin && form.errors.customer_id" class="text-sm text-red-600 dark:text-red-400">{{ form.errors.customer_id }}</p>
            </DrawerField>
            <DrawerField v-if="!origin && templates.length" label="Sjabloon">
                <ComboBox :options="templates" v-model="form.template_id" placeholder="Leeg beginnen" />
            </DrawerField>
            <DrawerField v-if="origin" label="Overnemen">
                <p v-if="!start" class="py-2 text-sm text-gray-400">Laden…</p>
                <p v-else-if="!start.choices.length" class="py-2 text-sm text-gray-400">Niets om over te nemen; je begint leeg.</p>
                <label v-for="choice in start?.choices ?? []" :key="choice.key" class="flex items-start gap-2 py-1 text-sm">
                    <input v-model="form.origin_lines" type="checkbox" :value="choice.key" class="mt-0.5 rounded border-gray-300 text-lavoro-blue" />
                    <span class="min-w-0">
                        {{ choice.label }}
                        <span v-if="choice.detail" class="text-xs text-gray-500 dark:text-slate-400">· {{ choice.detail }}</span>
                    </span>
                </label>
            </DrawerField>
            <DrawerField label="Offertedatum">
                <TextInput v-model="form.quote_date" type="date"
                    :hasError="Boolean(form.errors.quote_date)" :errorMessage="form.errors.quote_date" />
            </DrawerField>
            <DrawerField label="Geldig tot">
                <TextInput v-model="form.valid_until" type="date" placeholder="Een maand na de offertedatum"
                    :hasError="Boolean(form.errors.valid_until)" :errorMessage="form.errors.valid_until" />
            </DrawerField>
        </div>
        <p v-for="error in otherErrors" :key="error" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="open = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submit" :disabled="form.processing || (origin && !start)"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import { useForm } from '@inertiajs/vue3'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import DrawerField from '@/Components/UI/DrawerField.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { useComboSearch } from '@/Composables/useComboSearch'
import { todayIso } from '@/Utilities/Utilities'

/**
 * A new quote: empty, from a template, or from a werkbon or storing. The
 * customer starts empty on purpose, as on a new project; from a werkbon or
 * storing it is theirs, with the address and project, and what is on it is
 * listed to take over, all ticked.
 */
const props = defineProps({
    customers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    templates: { type: Array, default: () => [] },
    /** What the quote is made from: { kind: 'serviceorder' | 'ticket', id }. */
    origin: { type: Object, default: null },
})

const open = defineModel({ type: Boolean, default: false })

const { options: customerOptions, searching: customersSearching, search: searchCustomers } =
    useComboSearch('customers', props.customers, props.customersUseAjax)

const start = ref(null)

const form = useForm({
    title: '',
    customer_id: null,
    quote_date: todayIso(),
    valid_until: '',
    template_id: null,
    origin_lines: [],
})

/** What the server refused that no field above shows, such as the werkbon's project or address. */
const FIELDS = ['title', 'customer_id', 'quote_date', 'valid_until']
const otherErrors = computed(() => Object.entries(form.errors)
    .filter(([field]) => !FIELDS.includes(field))
    .map(([, message]) => message))

watch(open, async (opened) => {
    if (!opened || !props.origin || start.value) return
    const { data } = await axios.get(`/quotes/origin/${props.origin.kind}/${props.origin.id}`)
    start.value = data
    form.title = data.title
    form.customer_id = data.customer_id
    form.origin_lines = data.choices.map(choice => choice.key)
}, { immediate: true })

function submit() {
    form.transform(data => ({
        ...data,
        valid_until: data.valid_until || null,
        ...(props.origin ? {
            origin_type: props.origin.kind,
            origin_id: props.origin.id,
            location_id: start.value.location_id,
            project_id: start.value.project_id,
        } : { origin_lines: undefined }),
    })).post('/quotes', { onSuccess: () => { open.value = false; form.reset() } })
}
</script>
