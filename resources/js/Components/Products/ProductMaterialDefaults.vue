<template>
    <BoxComponent>
        <SectionHeader :icon="PackagePlusIcon" title="Standaardmaterialen op offertes" chapter="materials" border
            subtitle="Wat er meekomt als dit product wordt aangeboden. Het aantal is per stuk van het product." />

        <div v-if="defaults.length" class="divide-y divide-gray-200 dark:divide-slate-700">
            <div v-for="row in defaults" :key="row.id + '-' + row.quantity + '-' + row.prompt"
                class="grid grid-cols-12 gap-2 py-2 items-center text-sm">
                <div class="col-span-12 sm:col-span-5 font-medium">{{ row.name }}</div>
                <div class="col-span-4 sm:col-span-2">
                    <input type="number" step="any" min="0" :value="row.quantity" :class="field"
                        aria-label="Aantal per stuk" @change="update(row, { quantity: $event.target.value })" />
                </div>
                <div class="col-span-2 sm:col-span-1 text-gray-500">{{ row.unit }}</div>
                <div class="col-span-5 sm:col-span-3">
                    <select :value="row.prompt" :class="field" aria-label="Meenemen"
                        @change="update(row, { prompt: $event.target.value })">
                        <option v-for="prompt in prompts" :key="prompt.id" :value="prompt.id">{{ prompt.name }}</option>
                    </select>
                </div>
                <div class="col-span-1 flex justify-end">
                    <button type="button" @click="remove(row)" v-tooltip="'Niet meer meenemen'">
                        <TrashIcon class="size-4 text-red-500" />
                    </button>
                </div>
                <p v-for="error in (changing === row.id ? rowForm.errors : {})" :key="error"
                    class="col-span-12 text-xs text-red-600 dark:text-red-400">{{ error }}</p>
            </div>
        </div>
        <p v-else class="py-3 text-sm text-gray-400 dark:text-slate-500">
            Nog geen standaardmaterialen. Een airco-unit kan bijvoorbeeld altijd leidingwerk en een muurbeugel meenemen,
            en een condenspomp voorstellen.
        </p>

        <div class="mt-3 grid grid-cols-12 gap-2 items-start">
            <div class="col-span-12 sm:col-span-6">
                <ComboBox v-model="form.material_id" :options="materialOptions" :has-external-searching="true"
                    :disable-internal-filter="true" :searching="searching" placeholder="Zoek een materiaal"
                    @change="search" :has-error="Boolean(form.errors.material_id)" :error-message="form.errors.material_id" />
            </div>
            <div class="col-span-4 sm:col-span-2">
                <TextInput v-model="form.quantity" type="number" placeholder="Per stuk"
                    :has-error="Boolean(form.errors.quantity)" :error-message="form.errors.quantity" />
            </div>
            <div class="col-span-8 sm:col-span-3">
                <ComboBox v-model="form.prompt" :options="prompts" :has-error="Boolean(form.errors.prompt)"
                    :error-message="form.errors.prompt" />
            </div>
            <div class="col-span-12 sm:col-span-1">
                <button type="button" @click="add" :disabled="!form.material_id || form.processing"
                    class="w-full px-3 py-2 rounded-md text-sm font-medium bg-lavoro-blue text-white disabled:opacity-50">
                    <PlusIcon class="size-4 mx-auto" />
                </button>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { PackagePlusIcon, PlusIcon, TrashIcon } from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { useComboSearch } from '@/Composables/useComboSearch'

/**
 * Which materials come along when this product is offered, and how: always,
 * asked and ticked on, or only suggested. Part of the quotes module.
 */
const props = defineProps({
    productId: { type: Number, required: true },
    defaults: { type: Array, required: true },
    prompts: { type: Array, required: true },
})

const field = 'w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 text-sm px-2 py-1.5'

const { options, searching, search } = useComboSearch('materials', [], true)

const materialOptions = computed(() => options.value
    .filter(material => !props.defaults.some(row => row.material_id === material.id))
    .map(material => ({ id: material.id, name: material.name + (material.code ? ` (${material.code})` : '') })))

const form = useForm({ material_id: null, quantity: 1, prompt: 'ask' })

/** One form for changing a row, and the row it was last used for, so its errors show there. */
const rowForm = useForm({})
const changing = ref(null)

const base = computed(() => `/products/${props.productId}/material-defaults`)

function add() {
    form.post(base.value, { preserveScroll: true, onSuccess: () => form.reset() })
}

function update(row, changes) {
    changing.value = row.id
    rowForm.transform(() => changes).patch(`${base.value}/${row.id}`, { preserveScroll: true })
}

function remove(row) {
    if (!confirm(`${row.name} niet meer meenemen met dit product? Offertes die het al hebben houden het.`)) return
    router.delete(`${base.value}/${row.id}`, { preserveScroll: true })
}
</script>
