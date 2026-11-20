<template>
    <BreadcrumbComponent :items="[{ label: 'Offertes', href: '/quotes' }, { label: 'Instellingen' }]" />

    <header class="mt-4 mb-6 flex items-center gap-4">
        <div class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-blue-50 dark:bg-blue-900/30">
            <SettingsIcon class="size-7 text-lavoro-blue" />
        </div>
        <div>
            <h1 class="text-2xl font-bold">Offerte-instellingen</h1>
            <p class="text-sm text-gray-500 dark:text-slate-400">Wat voor elke offerte geldt, tenzij je het op de offerte zelf anders kiest.</p>
        </div>
    </header>

    <ChaptersComponent>
        <ChapterHeaders>
            <ChapterHeader v-for="(chapter, index) in CHAPTERS" :key="chapter.label" :index="index">
                <span class="inline-flex items-center gap-2"><component :is="chapter.icon" class="size-4" /> {{ chapter.label }}</span>
            </ChapterHeader>
        </ChapterHeaders>
        <ChapterContents>
            <template #chapter-0>
                <BoxComponent class="max-w-2xl">
                    <SectionHeader :icon="FileTextIcon" title="Wat de klant ziet" chapter="details"
                        subtitle="De kolommen op de pagina van de klant en op de PDF. Zonder prijs per eenheid en regeltotaal ziet de klant alleen het totaal." />
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="(label, choice) in layoutChoices" :key="choice" class="flex items-center gap-2 text-sm">
                            <input v-model="form.layout[choice]" type="checkbox" class="rounded border-gray-300 text-lavoro-blue" @change="save('layout')" />
                            {{ label }}
                        </label>
                    </div>

                    <div class="mt-6 space-y-4 border-t border-gray-100 dark:border-slate-800 pt-4">
                        <div>
                            <span class="text-xs font-bold text-slate-400">Betaling</span>
                            <textarea v-model="form.payment_terms" rows="2" @change="save('payment_terms')"
                                class="mt-1 block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 text-sm p-2" />
                            <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Waarmee elke nieuwe offerte begint.</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <span class="text-xs font-bold text-slate-400">Ten hoogste vooraf van een particulier</span>
                                <div class="flex items-center gap-2">
                                    <TextInput v-model="form.max_upfront_percent" type="number" @change="save('max_upfront_percent')"
                                        :has-error="Boolean(form.errors.max_upfront_percent)" :error-message="form.errors.max_upfront_percent" />
                                    <span class="text-sm text-gray-500">%</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">AVIC 25%, NVKL 35%, de wet 50%.</p>
                            </div>
                            <div>
                                <span class="text-xs font-bold text-slate-400">Uurtarief</span>
                                <CurrencyInput v-model="form.hourly_rate" @change="save('hourly_rate')" aria-label="Uurtarief" />
                                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Voor de gewerkte uren van een werkbon op een offerte.</p>
                            </div>
                        </div>
                    </div>
                </BoxComponent>
            </template>

            <template #chapter-1>
                <BoxComponent class="max-w-2xl">
                    <SectionHeader :icon="BellRingIcon" title="Opvolging" chapter="details"
                        subtitle="Wat er vanzelf gebeurt met een offerte die verstuurd is en nog geen antwoord heeft. Laat een veld leeg om het uit te zetten." />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <span class="text-xs font-bold text-slate-400">Herinnering aan de klant na</span>
                            <div class="flex items-center gap-2">
                                <TextInput v-model="form.reminder_days" type="number" placeholder="Uit" @change="save('reminder_days')"
                                    :has-error="Boolean(form.errors.reminder_days)" :error-message="form.errors.reminder_days" />
                                <span class="text-sm text-gray-500">dagen</span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Eén keer, met dezelfde versie en een nieuwe link.</p>
                        </div>
                        <div>
                            <span class="text-xs font-bold text-slate-400">Melding aan de maker</span>
                            <div class="flex items-center gap-2">
                                <TextInput v-model="form.expiry_notice_days" type="number" placeholder="Uit" @change="save('expiry_notice_days')"
                                    :has-error="Boolean(form.errors.expiry_notice_days)" :error-message="form.errors.expiry_notice_days" />
                                <span class="text-sm text-gray-500">dagen voor het verlopen</span>
                            </div>
                        </div>
                    </div>
                </BoxComponent>
            </template>

            <template #chapter-2>
                <QuoteTermsSettings :settings="settings" />
            </template>

            <template #chapter-3>
                <BoxComponent class="max-w-2xl">
                    <SectionHeader :icon="RulerIcon" title="Eenheden" chapter="details"
                        subtitle="Waaruit je op een offerteregel kiest. De eenheden van je materialen komen er vanzelf bij. Versleep een eenheid om de volgorde te veranderen." />
                    <draggable v-model="units" handle=".draghandle" :animation="150" :class="LIST" @end="order('units', units)">
                        <QuoteSettingsRow v-for="unit in units" :key="unit.id + '-' + unit.name" :item="unit" :url="`/quote-settings/units/${unit.id}`"
                            label="Naam van de eenheid" :confirm-text="`De eenheid ${unit.name} verwijderen? Offertes die hem al gebruiken houden hem.`" />
                    </draggable>
                    <p v-if="!units.length" class="py-4 text-sm text-gray-400 dark:text-slate-500">Nog geen eenheden.</p>
                    <QuoteSettingsAddRow url="/quote-settings/units" placeholder="Nieuwe eenheid, bijvoorbeeld m³ of strekkende meter" />
                </BoxComponent>
            </template>

            <template #chapter-4>
                <BoxComponent class="max-w-2xl">
                    <SectionHeader :icon="CircleXIcon" title="Redenen van verlies" chapter="details"
                        subtitle="Waaruit je kiest als een offerte is afgewezen, zodat je in de lijst ziet waarom offertes verloren gaan. De klant kiest zelf uit de redenen die voor de klant aan staan." />
                    <draggable v-model="reasons" handle=".draghandle" :animation="150" :class="LIST" @end="order('reasons', reasons)">
                        <QuoteSettingsRow v-for="reason in reasons" :key="reason.id + '-' + reason.name" :item="reason"
                            :url="`/quote-settings/reasons/${reason.id}`" label="Reden"
                            :confirm-text="`De reden ${reason.name} verwijderen? Offertes die erom verloren gingen, zeggen dan niet meer waarom.`">
                            <span class="shrink-0 text-xs text-gray-500 dark:text-slate-400">{{ reason.quotes_count }} offertes</span>
                            <label class="flex shrink-0 items-center gap-2 text-xs text-gray-600 dark:text-slate-300">
                                <SwitchComponent :model-value="reason.customer_can_pick"
                                    @update:model-value="(value) => patchReason(reason, { customer_can_pick: value })" />
                                voor de klant
                            </label>
                        </QuoteSettingsRow>
                    </draggable>
                    <QuoteSettingsAddRow url="/quote-settings/reasons" placeholder="Nieuwe reden, bijvoorbeeld levertijd te lang" />
                </BoxComponent>
            </template>

            <template #chapter-5>
                <BoxComponent class="max-w-2xl">
                    <SectionHeader :icon="BookmarkIcon" title="Sjablonen" chapter="details"
                        subtitle="Offertes om een nieuwe mee te beginnen, zoals airco voor één ruimte. Je maakt er een vanuit een offerte: kies daar in het menu Bewaren als sjabloon." />
                    <div :class="LIST">
                        <QuoteSettingsRow v-for="template in templates" :key="template.id + '-' + template.name" :item="template"
                            :url="`/quote-settings/templates/${template.id}`" label="Naam van het sjabloon" :draggable="false"
                            :confirm-text="`Het sjabloon ${template.name} verwijderen? Offertes die ermee gemaakt zijn blijven zoals ze zijn.`">
                            <span class="shrink-0 text-xs text-gray-500 dark:text-slate-400">
                                {{ template.lines }} regels<template v-if="template.created_by"> · {{ template.created_by }}</template>
                            </span>
                        </QuoteSettingsRow>
                    </div>
                    <p v-if="!templates.length" class="py-4 text-sm text-gray-400 dark:text-slate-500">Nog geen sjablonen.</p>
                </BoxComponent>
            </template>
        </ChapterContents>
    </ChaptersComponent>
</template>

<script setup>
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { VueDraggableNext as draggable } from 'vue-draggable-next'
import {
    BellRingIcon, BookmarkIcon, CircleXIcon, FileTextIcon, RulerIcon, ScaleIcon, SettingsIcon,
} from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ChapterContents from '@/Components/Chapters/ChapterContents.vue'
import ChapterHeader from '@/Components/Chapters/ChapterHeader.vue'
import ChapterHeaders from '@/Components/Chapters/ChapterHeaders.vue'
import ChaptersComponent from '@/Components/Chapters/ChaptersComponent.vue'
import QuoteSettingsAddRow from '@/Components/Quotes/QuoteSettingsAddRow.vue'
import QuoteSettingsRow from '@/Components/Quotes/QuoteSettingsRow.vue'
import QuoteTermsSettings from '@/Components/Quotes/QuoteTermsSettings.vue'
import { useSaveField } from '@/Components/Quotes/useSaveField'
import BreadcrumbComponent from '@/Components/UI/BreadcrumbComponent.vue'
import CurrencyInput from '@/Components/UI/CurrencyInput.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import TextInput from '@/Components/UI/TextInput.vue'

/** The settings that hold for every quote, a chapter each. Every field is saved the moment it is left. */
const props = defineProps({
    settings: { type: Object, required: true },
    layoutChoices: { type: Object, required: true },
    units: { type: Array, required: true },
    reasons: { type: Array, required: true },
    templates: { type: Array, required: true },
})

const CHAPTERS = [
    { label: 'Offerte', icon: FileTextIcon },
    { label: 'Opvolging', icon: BellRingIcon },
    { label: 'Voorwaarden', icon: ScaleIcon },
    { label: 'Eenheden', icon: RulerIcon },
    { label: 'Redenen', icon: CircleXIcon },
    { label: 'Sjablonen', icon: BookmarkIcon },
]

const LIST = 'divide-y divide-gray-100 dark:divide-slate-800 rounded-lg ring-1 ring-gray-200 dark:ring-slate-700'

const form = useForm({ ...props.settings, layout: { ...props.settings.layout } })
const save = useSaveField(form, () => '/quote-settings', () => props.settings)

/** The lists as they are dragged; the server's order again after every reload. */
const units = ref([...props.units])
const reasons = ref([...props.reasons])
watch(() => props.units, (rows) => { units.value = [...rows] })
watch(() => props.reasons, (rows) => { reasons.value = [...rows] })

function order(list, rows) {
    const key = list === 'units' ? 'unit_ids' : 'reason_ids'
    router.put(`/quote-settings/${list}/order`, { [key]: rows.map(row => row.id) }, { preserveScroll: true })
}

function patchReason(reason, changes) {
    router.patch(`/quote-settings/reasons/${reason.id}`, changes, { preserveScroll: true })
}
</script>
