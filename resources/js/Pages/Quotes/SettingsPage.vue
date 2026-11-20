<template>
    <BreadcrumbComponent :items="[{ label: 'Offertes', href: '/quotes' }, { label: 'Instellingen' }]" />

    <header class="mt-4 mb-6 flex items-center gap-4">
        <div class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-blue-50 dark:bg-blue-900/30">
            <SettingsIcon class="size-7 text-lavoro-blue" />
        </div>
        <div>
            <h1 class="text-2xl font-bold">Offerte-instellingen</h1>
            <p class="text-sm text-gray-500 dark:text-slate-400">Wat voor elke offerte geldt.</p>
        </div>
    </header>

    <ChaptersComponent>
        <ChapterHeaders>
            <ChapterHeader :index="0">
                <span class="inline-flex items-center gap-2"><RulerIcon class="size-4" /> Eenheden</span>
            </ChapterHeader>
        </ChapterHeaders>
        <ChapterContents>
            <template #chapter-0>
                <BoxComponent class="max-w-2xl">
                    <SectionHeader :icon="RulerIcon" title="Eenheden" chapter="details"
                        subtitle="Waaruit je op een offerteregel kiest. De eenheden van je materialen komen er vanzelf bij. Versleep een eenheid om de volgorde te veranderen." />

                    <draggable v-model="rows" handle=".draghandle" :animation="150"
                        class="divide-y divide-gray-100 dark:divide-slate-800 rounded-lg ring-1 ring-gray-200 dark:ring-slate-700" @end="reorder">
                        <QuoteUnitRow v-for="unit in rows" :key="unit.id + '-' + unit.name" :unit="unit" />
                    </draggable>
                    <p v-if="!rows.length" class="py-4 text-sm text-gray-400 dark:text-slate-500">Nog geen eenheden.</p>

                    <form class="mt-4 flex items-start gap-2" @submit.prevent="add">
                        <div class="flex-1">
                            <TextInput v-model="form.name" placeholder="Nieuwe eenheid, bijvoorbeeld m³ of strekkende meter"
                                :has-error="Boolean(form.errors.name)" :error-message="form.errors.name" />
                        </div>
                        <button type="submit" :disabled="!form.name || form.processing"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
                            <PlusIcon class="size-4" /> Toevoegen
                        </button>
                    </form>
                </BoxComponent>
            </template>
        </ChapterContents>
    </ChaptersComponent>
</template>

<script setup>
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { VueDraggableNext as draggable } from 'vue-draggable-next'
import { PlusIcon, RulerIcon, SettingsIcon } from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ChapterContents from '@/Components/Chapters/ChapterContents.vue'
import ChapterHeader from '@/Components/Chapters/ChapterHeader.vue'
import ChapterHeaders from '@/Components/Chapters/ChapterHeaders.vue'
import ChaptersComponent from '@/Components/Chapters/ChaptersComponent.vue'
import QuoteUnitRow from '@/Components/Quotes/QuoteUnitRow.vue'
import BreadcrumbComponent from '@/Components/UI/BreadcrumbComponent.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import TextInput from '@/Components/UI/TextInput.vue'

/** The settings that hold for every quote, a chapter each. The units come first. */
const props = defineProps({
    units: { type: Array, required: true },
})

const rows = ref([...props.units])
watch(() => props.units, (units) => { rows.value = [...units] })

const form = useForm({ name: '' })

function add() {
    form.post('/quote-settings/units', { preserveScroll: true, onSuccess: () => form.reset() })
}

function reorder() {
    router.put('/quote-settings/units/order', { unit_ids: rows.value.map(unit => unit.id) }, { preserveScroll: true })
}
</script>
