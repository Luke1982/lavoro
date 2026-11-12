<template>
    <div class="p-6 max-w-2xl">
        <h1 class="text-xl font-semibold text-gray-900 mb-6">Instellingen</h1>

        <section>
            <h2 class="text-base font-semibold text-gray-900 mb-4">Locatie tracking</h2>

            <form @submit.prevent="submit" class="space-y-5">
                <div class="flex gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Starttijd</label>
                        <input type="time" v-model="form.start"
                            class="rounded-md border-gray-300 shadow-sm text-sm focus:border-lavoro-blue focus:ring-lavoro-blue bg-white" />
                        <p v-if="form.errors.start" class="mt-1 text-xs text-red-600">{{ form.errors.start }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Eindtijd</label>
                        <input type="time" v-model="form.end"
                            class="rounded-md border-gray-300 shadow-sm text-sm focus:border-lavoro-blue focus:ring-lavoro-blue bg-white" />
                        <p v-if="form.errors.end" class="mt-1 text-xs text-red-600">{{ form.errors.end }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Dagen</label>
                    <div class="flex gap-2 flex-wrap">
                        <button v-for="day in DAYS" :key="day.value" type="button" @click="toggleDay(day.value)" :class="[
                            'px-3 py-1.5 rounded-md text-sm font-medium border transition-colors',
                            form.days.includes(day.value)
                                ? 'bg-lavoro-blue border-lavoro-blue text-white'
                                : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                        ]">
                            {{ day.label }}
                        </button>
                    </div>
                    <p v-if="form.errors.days" class="mt-1 text-xs text-red-600">{{ form.errors.days }}</p>
                </div>

                <div>
                    <button type="submit" :disabled="form.processing"
                        class="inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-lavoro-blue disabled:opacity-50">
                        Opslaan
                    </button>
                </div>
            </form>
        </section>

        <section class="mt-10">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Werkbon</h2>

            <form @submit.prevent="submitClosingText" class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Afsluitende tekst</label>
                    <p class="text-xs text-gray-500 mb-2">
                        Deze tekst wordt onderaan de werkbon-PDF getoond, direct boven de voettekst.
                    </p>
                    <textarea v-model="closingTextForm.serviceorder_closing_text" rows="5"
                        class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-lavoro-blue focus:ring-lavoro-blue bg-white"></textarea>
                    <p v-if="closingTextForm.errors.serviceorder_closing_text" class="mt-1 text-xs text-red-600">
                        {{ closingTextForm.errors.serviceorder_closing_text }}
                    </p>
                </div>

                <div>
                    <button type="submit" :disabled="closingTextForm.processing"
                        class="inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-lavoro-blue disabled:opacity-50">
                        Opslaan
                    </button>
                </div>
            </form>

            <div class="mt-6">
                <form @submit.prevent="submitMinImages" class="space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Minimum aantal foto's</label>
                        <p class="text-xs text-gray-500 mb-2">
                            Het minimum aantal publieke foto's dat toegevoegd moet zijn voordat een werkbon gesloten kan worden. Stel in op 0 voor geen minimum.
                        </p>
                        <input
                            type="number"
                            min="0"
                            v-model.number="minImagesForm.min_images"
                            class="rounded-md border-gray-300 shadow-sm text-sm focus:border-lavoro-blue focus:ring-lavoro-blue bg-white w-24"
                        />
                        <p v-if="minImagesForm.errors.min_images" class="mt-1 text-xs text-red-600">
                            {{ minImagesForm.errors.min_images }}
                        </p>
                    </div>

                    <div>
                        <button
                            type="submit"
                            :disabled="minImagesForm.processing"
                            class="inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-lavoro-blue disabled:opacity-50"
                        >
                            Opslaan
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="mt-10">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Planning</h2>

            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-700">Onbeschikbaarheid overschrijven toestaan</p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Wanneer ingeschakeld kunnen planners een afspraak inplannen op een geblokkeerd tijdslot na bevestiging.
                    </p>
                </div>
                <SwitchComponent v-model="overrideForm.value" @update="submitOverride" />
            </div>

            <form class="mt-6 space-y-3" @submit.prevent="submitReminder">
                <div>
                    <label for="appointment-reminder-minutes" class="block text-sm font-medium text-gray-700 mb-1">
                        Afspraakherinnering
                    </label>
                    <p class="text-xs text-gray-500 mb-2">
                        Hoeveel minuten voor het begin van een afspraak de monteurs die erop staan een herinnering krijgen,
                        met de route en het nummer van de klant. Iedereen kan de herinnering voor zichzelf uitzetten onder
                        Meldingen.
                    </p>
                    <div class="flex items-center gap-2">
                        <input
                            id="appointment-reminder-minutes"
                            v-model.number="reminderForm.minutes"
                            type="number"
                            class="rounded-md border-gray-300 shadow-sm text-sm focus:border-lavoro-blue focus:ring-lavoro-blue bg-white w-24"
                        />
                        <span class="text-sm text-gray-700">minuten van tevoren</span>
                    </div>
                    <p v-if="reminderForm.errors.minutes" class="mt-1 text-xs text-red-600">
                        {{ reminderForm.errors.minutes }}
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="reminderForm.processing"
                    class="inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-lavoro-blue disabled:opacity-50"
                >
                    Opslaan
                </button>
            </form>
        </section>

        <section class="mt-10">
            <h2 class="text-base font-semibold text-gray-900 mb-1">Kaart aankomende keuringen</h2>
            <p class="text-xs text-gray-500 mb-4">
                De kleuren van de spelden en de legenda. Een adres krijgt de kleur van de eerste groep waarbinnen
                zijn eerstvolgende keuring valt; wat na de laatste groep komt, krijgt de kleur van Later.
            </p>

            <form @submit.prevent="submitMapDueColors" class="space-y-3">
                <div class="flex items-center gap-3">
                    <input type="color" v-model="mapDueForm.expired" :class="COLOR_INPUT" />
                    <span class="text-sm text-gray-700">Verlopen</span>
                </div>
                <p v-if="mapDueForm.errors.expired" class="text-xs text-red-600">{{ mapDueForm.errors.expired }}</p>

                <div v-for="(band, index) in mapDueForm.bands" :key="index">
                    <div class="flex items-center gap-3">
                        <input type="color" v-model="band.color" :class="COLOR_INPUT" />
                        <span class="text-sm text-gray-700">Binnen</span>
                        <input type="number" v-model.number="band.days"
                            class="w-20 rounded-md border-gray-300 shadow-sm text-sm focus:border-lavoro-blue focus:ring-lavoro-blue bg-white" />
                        <span class="text-sm text-gray-700">{{ band.days === 1 ? 'dag' : 'dagen' }}</span>
                        <button type="button" title="Groep verwijderen" class="text-gray-400 hover:text-red-600"
                            @click="removeDueBand(index)">
                            <TrashIcon class="size-4" />
                        </button>
                    </div>
                    <p v-if="mapDueForm.errors[`bands.${index}.days`]" class="mt-1 text-xs text-red-600">
                        {{ mapDueForm.errors[`bands.${index}.days`] }}
                    </p>
                    <p v-if="mapDueForm.errors[`bands.${index}.color`]" class="mt-1 text-xs text-red-600">
                        {{ mapDueForm.errors[`bands.${index}.color`] }}
                    </p>
                </div>

                <button type="button" class="flex items-center gap-1 text-sm text-lavoro-blue hover:underline"
                    @click="addDueBand">
                    <PlusIcon class="size-4" />
                    Groep toevoegen
                </button>
                <p v-if="mapDueForm.errors.bands" class="text-xs text-red-600">{{ mapDueForm.errors.bands }}</p>

                <div class="flex items-center gap-3">
                    <input type="color" v-model="mapDueForm.later" :class="COLOR_INPUT" />
                    <span class="text-sm text-gray-700">Later</span>
                </div>
                <p v-if="mapDueForm.errors.later" class="text-xs text-red-600">{{ mapDueForm.errors.later }}</p>

                <div class="flex items-center gap-4 pt-2">
                    <button type="submit" :disabled="mapDueForm.processing"
                        class="inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-lavoro-blue disabled:opacity-50">
                        Opslaan
                    </button>
                    <button type="button" class="text-sm text-gray-600 hover:underline"
                        @click="loadDueColors(mapDueColorsDefaults)">
                        Standaardkleuren terugzetten
                    </button>
                </div>
            </form>
        </section>

        <section v-if="snelStartConnected" class="mt-10">
            <h2 class="text-base font-semibold text-gray-900 mb-4">SnelStart</h2>

            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-700">Bestaande klanten bijwerken bij de nachtelijke import</p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Elke nacht worden klanten en materialen uit SnelStart opgehaald. Nieuwe klanten komen er altijd bij.
                        Staat dit uit, dan blijven klanten die al in Lavoro staan zoals ze zijn.
                    </p>
                </div>
                <SwitchComponent v-model="snelStartForm.value" @update="submitSnelStart" />
            </div>
        </section>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'

const props = defineProps({
    locationTracking: { type: Object, required: true },
    serviceOrderClosingText: { type: String, default: '' },
    allowOverrideUnavailability: { type: Boolean, default: false },
    serviceOrderMinImages: { type: Number, default: 0 },
    snelStartConnected: { type: Boolean, default: false },
    snelStartNightlyUpdatesCustomers: { type: Boolean, default: true },
    mapDueColors: { type: Object, required: true },
    mapDueColorsDefaults: { type: Object, required: true },
    appointmentReminderMinutes: { type: Number, required: true },
})

const COLOR_INPUT = 'h-8 w-8 shrink-0 cursor-pointer rounded border border-gray-300 bg-white p-0.5'

const copyDueColors = ({ expired, bands, later }) => ({
    expired,
    bands: bands.map((band) => ({ ...band })),
    later,
})

const mapDueForm = useForm(copyDueColors(props.mapDueColors))

const loadDueColors = (colors) => Object.assign(mapDueForm, copyDueColors(colors))

function addDueBand() {
    const lastDays = Number(mapDueForm.bands.at(-1)?.days) || 0
    mapDueForm.bands.push({ days: lastDays + 30, color: '#6b7280' })
}

function removeDueBand(index) {
    mapDueForm.bands.splice(index, 1)
    mapDueForm.clearErrors()
}

function submitMapDueColors() {
    mapDueForm.put('/admin/settings/map-due-colors', {
        preserveScroll: true,
        onSuccess: () => loadDueColors(props.mapDueColors),
    })
}

const DAYS = [
    { value: 1, label: 'Ma' },
    { value: 2, label: 'Di' },
    { value: 3, label: 'Wo' },
    { value: 4, label: 'Do' },
    { value: 5, label: 'Vr' },
    { value: 6, label: 'Za' },
    { value: 7, label: 'Zo' },
]

const form = useForm({
    start: props.locationTracking.start,
    end: props.locationTracking.end,
    days: [...props.locationTracking.days],
})

function toggleDay(value) {
    const index = form.days.indexOf(value)
    if (index === -1) {
        form.days.push(value)
    } else {
        form.days.splice(index, 1)
    }
}

const closingTextForm = useForm({
    serviceorder_closing_text: props.serviceOrderClosingText,
})

const overrideForm = useForm({
    value: props.allowOverrideUnavailability,
})

const snelStartForm = useForm({
    value: props.snelStartNightlyUpdatesCustomers,
})

const minImagesForm = useForm({
    min_images: props.serviceOrderMinImages,
})

function submitMinImages() {
    minImagesForm.put('/admin/settings/serviceorder-min-images')
}

const reminderForm = useForm({
    minutes: props.appointmentReminderMinutes,
})

function submitReminder() {
    reminderForm.put('/admin/settings/appointment-reminder', { preserveScroll: true })
}

function submit() {
    form.put('/admin/settings/location-tracking')
}

function submitClosingText() {
    closingTextForm.put('/admin/settings/serviceorder-closing-text')
}

function submitOverride() {
    overrideForm.put('/admin/settings/allow-override-unavailability')
}

function submitSnelStart() {
    snelStartForm.put('/admin/settings/snelstart-nightly-customers')
}
</script>
