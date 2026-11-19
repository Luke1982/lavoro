<template>
    <IndexHeaderComponent title="Prijsformules"
        subtitle="Bouw met blokken op wat een onderhoudscontract kost: per type, model, kenmerk, combinatie of aantal"
        :show-search="false" add-label="Nieuwe formule" @add="showCreate = true"
        :can-add="hasPermission('priceformula.create')">
        <template #actions>
            <AssistantButton />
            <TourButton tour="prijsformules" />
        </template>
    </IndexHeaderComponent>

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="shownFormulas.length" data-tour="formulas.list">
            <div
                class="hidden rounded-t-lavoro-sm border-b-lavoro-darkergray bg-lavoro-lightgray p-4 text-sm font-bold md:grid md:grid-cols-12 dark:border-b-slate-600 dark:bg-slate-800/50">
                <div class="col-span-5">Naam</div>
                <div class="col-span-2">Versie</div>
                <div class="col-span-3">Contracten</div>
                <div class="col-span-2">Sjablonen</div>
            </div>
            <div v-for="(formula, index) in shownFormulas" :key="formula.id" :data-tour="index === 0 ? 'formulas.row' : null"
                class="border-b-2 border-b-lavoro-gray-150 p-4 text-sm md:grid md:grid-cols-12 md:items-center dark:border-b-slate-800">
                <div class="flex flex-col py-1 md:col-span-5 md:py-0">
                    <span v-if="formula.example" class="text-xs italic text-amber-700 dark:text-amber-400">Voorbeeld uit de rondleiding</span>
                    <span v-if="formula.example" class="font-medium text-gray-900 dark:text-slate-100">{{ formula.name }}</span>
                    <Link v-else :href="`/priceformulas/${formula.id}`" class="font-medium text-gray-900 hover:underline dark:text-slate-100">
                        {{ formula.name }}
                    </Link>
                    <span v-if="formula.description" class="text-gray-500 dark:text-slate-400">{{ formula.description }}</span>
                </div>
                <div class="py-1 text-gray-500 md:col-span-2 md:py-0 dark:text-slate-400" data-cell="version">
                    <span class="md:hidden">Versie </span>{{ formula.version ?? '—' }}
                </div>
                <div class="flex flex-wrap items-center gap-2 py-1 md:col-span-3 md:py-0" data-cell="contracts">
                    <span class="text-gray-500 dark:text-slate-400">
                        {{ formula.contracts === 1 ? '1 contract' : `${formula.contracts} contracten` }}
                    </span>
                    <BadgeComponent v-if="formula.outdated" color="yellow" :has-dot="false"
                        :url="formula.example ? null : `/priceformulas/${formula.id}`">
                        {{ formula.outdated }} op een oudere versie
                    </BadgeComponent>
                </div>
                <div class="py-1 text-gray-500 md:col-span-2 md:py-0 dark:text-slate-400" data-cell="templates">
                    {{ formula.templates.length ? formula.templates.join(', ') : '—' }}
                </div>
            </div>
        </div>
        <p v-else class="p-4 text-sm text-gray-500 dark:text-slate-400">
            Nog geen prijsformules. Een formule rekent uit wat een contract kost aan de hand van zijn machines.
        </p>
    </BoxComponent>

    <DrawerComponent v-model="showCreate" title="Nieuwe prijsformule"
        subtitle="Je begint met één prijs per machine; daarna breid je de formule uit op het canvas.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 gap-4 px-4 py-4 sm:grid-cols-3 sm:items-center sm:px-6" data-tour="formulas.new.name">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Naam</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.name" type="text" placeholder="Bijvoorbeeld Airco standaard"
                        :has-error="Boolean(form.errors.name)" :error-message="form.errors.name" />
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 px-4 py-4 sm:grid-cols-3 sm:items-center sm:px-6" data-tour="formulas.new.description">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Omschrijving</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.description" type="text" placeholder="Optioneel"
                        :has-error="Boolean(form.errors.description)" :error-message="form.errors.description" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="showCreate = false"
                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" :disabled="form.processing" data-tour="formulas.new.submit" @click="submit"
                    class="rounded-md bg-lavoro-blue px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60">
                    Aanmaken
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { hasPermission } from '@/Utilities/Utilities'
import TourButton from '@/Components/Tour/TourButton.vue'
import AssistantButton from '@/Components/Assistant/AssistantButton.vue'
import { useTour } from '@/Composables/useTour'

/** Every price formula, with how many contracts it prices and how many still wait for its newest version. */
const props = defineProps({
    formulas: { type: Array, default: () => [] },
})

const showCreate = ref(false)

/** Shown by the rondleiding while there are no formulas yet, and never saved. */
const exampleFormula = ref(null)
const shownFormulas = computed(() => (exampleFormula.value ? [exampleFormula.value] : props.formulas))

const firstRow = () => document.querySelector('[data-tour="formulas.row"]')

useTour('prijsformules', {
    showExampleFormula: () => {
        if (props.formulas.length) return null
        exampleFormula.value = {
            id: 'voorbeeld',
            example: true,
            name: 'Airco standaard',
            description: 'Airco per stuk met staffel, voorrijkosten per adres en korting',
            version: 3,
            contracts: 14,
            outdated: 2,
            templates: ['Jaarcontract airco'],
        }

        return () => { exampleFormula.value = null }
    },
    openCreate: () => {
        if (showCreate.value) return null
        showCreate.value = true

        return () => { showCreate.value = false }
    },
}, {
    spots: {
        'formulas.version': () => firstRow()?.querySelector('[data-cell=version]') ?? null,
        'formulas.contracts': () => firstRow()?.querySelector('[data-cell=contracts]') ?? null,
        'formulas.templates': () => firstRow()?.querySelector('[data-cell=templates]') ?? null,
    },
})

const form = useForm({ name: '', description: '' })

watch(showCreate, open => {
    if (open) return
    form.reset()
    form.clearErrors()
})

function submit() {
    form.post('/priceformulas')
}
</script>
