<template>
    <div class="flex items-center">
        <Link href="/internalannouncements" class="text-slate-400 text-sm font-medium">Aankondigingen</Link>
        <ChevronRightIcon class="size-4 text-gray-400 mx-2" />
        <span class="text-slate-800 dark:text-slate-100 font-bold text-sm">{{ announcement.title }}</span>
    </div>

    <div class="flex flex-col mt-6 mb-4">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold dark:text-slate-100">{{ announcement.title }}</h1>
            <BadgeComponent v-bind="announcementStatus(announcement)" />
        </div>
        <p class="text-gray-500 dark:text-slate-400 text-sm mt-1">
            {{ progressSentence }}
        </p>
    </div>

    <BoxComponent>
        <SectionHeader :icon="Megaphone" title="Het bericht"
            subtitle="Wat er onder in beeld staat tot iemand op Begrepen drukt." chapter="details" />
        <div class="grid grid-cols-1 gap-6" v-auto-animate>
            <EditableTextField v-model="form.title" type="input" label="Titel" :error="form.errors.title"
                :readonly="!canUpdate" @update="() => patch('title')" @revert="form.clearErrors('title')" />
            <EditableTextField v-model="form.body" type="textarea" label="Bericht" :error="form.errors.body"
                :readonly="!canUpdate" @update="() => patch('body')" @revert="form.clearErrors('body')" />
            <EditableTextField v-model="form.expires_on" type="input" inputType="date" label="Zichtbaar tot en met"
                placeholder="Tot iedereen bevestigd heeft" clearable :error="form.errors.expires_on"
                :readonly="!canUpdate" @update="() => patch('expires_on')"
                @revert="form.clearErrors('expires_on')" />
        </div>
    </BoxComponent>

    <!--
        Alleen voor wie hem mag wijzigen: zonder de namenlijst is een keuzeveld
        een leeg vak, en wie hem enkel inziet leest de doelgroep hieronder al
        voluit bij de bevestigingen.
    -->
    <BoxComponent v-if="canUpdate" class="mt-4">
        <SectionHeader :icon="UsersIcon" title="Doelgroep"
            subtitle="Wie de aankondiging krijgt. Wie al bevestigde blijft staan, ook als je hem eruit haalt."
            chapter="audience">
            <template #actions>
                <span class="text-xs text-gray-500 dark:text-slate-400">Aan iedereen</span>
                <SwitchComponent v-model="audience.is_for_everyone" />
            </template>
        </SectionHeader>
        <div v-auto-animate>
            <ComboBox v-if="!audience.is_for_everyone" v-model="audience.user_ids" :options="users" multiple
                placeholder="Kies gebruikers" :hasError="Boolean(form.errors.user_ids)"
                :errorMessage="form.errors.user_ids" />
            <p v-else class="text-sm text-gray-500 dark:text-slate-400">
                Alle gebruikers die er zijn op het moment dat je dit opslaat.
            </p>
            <div v-if="audienceChanged" class="mt-4 flex justify-end">
                <button type="button" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed"
                    @click="saveAudience">
                    Doelgroep opslaan
                </button>
            </div>
        </div>
    </BoxComponent>

    <BoxComponent class="mt-4">
        <SectionHeader :icon="ClipboardDocumentCheckIcon" title="Bevestigingen"
            subtitle="Wie hem gelezen heeft, en wanneer." chapter="signoff" />
        <div v-if="recipients.length" class="divide-y divide-gray-200 dark:divide-slate-700">
            <div v-for="recipient in recipients" :key="recipient.id"
                class="flex items-center justify-between gap-4 py-3 text-sm">
                <span class="font-medium text-gray-900 dark:text-slate-100">{{ recipient.name }}</span>
                <span v-if="recipient.acknowledged_at" class="text-gray-500 dark:text-slate-400">
                    {{ nlDate(recipient.acknowledged_at) }} om {{ nlTime(recipient.acknowledged_at) }}
                </span>
                <span v-else class="text-amber-600 dark:text-amber-400">Nog niet bevestigd</span>
            </div>
        </div>
        <p v-else class="text-sm text-gray-500 dark:text-slate-400">
            Deze aankondiging heeft geen ontvangers.
        </p>
    </BoxComponent>

    <BoxComponent class="mt-4">
        <SectionHeader :icon="TimelineIcon" title="Tijdlijn"
            subtitle="Alles wat er met deze aankondiging gebeurd is, op volgorde." chapter="timeline" />
        <TimelineComponent :activities="activities" />
    </BoxComponent>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import {
    ChevronRightIcon, ClipboardDocumentCheckIcon, UsersIcon,
} from '@heroicons/vue/24/outline'
import { Megaphone, TimelineIcon } from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue'
import { announcementStatus, hasPermission, nlDate, nlTime } from '@/Utilities/Utilities'

const props = defineProps({
    announcement: { type: Object, required: true },
    recipients: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    activities: { type: Array, default: () => [] },
})

const canUpdate = computed(() => hasPermission('internalannouncement.update'))

const progressSentence = computed(() => {
    const total = props.recipients.length

    if (!total) return 'Deze aankondiging heeft nog geen ontvangers'

    const done = props.recipients.filter(recipient => recipient.acknowledged_at).length

    return `${done} van de ${total} ontvangers ${done === 1 ? 'heeft' : 'hebben'} bevestigd`
})

const form = useForm({
    title: props.announcement.title,
    body: props.announcement.body,
    expires_on: props.announcement.expires_on ?? '',
})

/**
 * De doelgroep staat los van de velden erboven omdat hij als paar opgeslagen
 * wordt: "aan iedereen" en de namenlijst zeggen los van elkaar niets, en per
 * gekozen naam opslaan zou halverwege een lege lijst versturen.
 *
 * Eén vingerafdruk beantwoordt beide vragen die de sectie stelt: is er iets
 * veranderd sinds het opslaan, en is wat er op de server staat verschoven. De
 * namen op volgorde, want opnieuw kiezen in een andere volgorde is hetzelfde.
 */
const fingerprint = (is_for_everyone, user_ids) =>
    is_for_everyone ? 'iedereen' : [...user_ids].map(Number).sort((a, b) => a - b).join()

const savedAudience = () => ({
    is_for_everyone: props.announcement.is_for_everyone,
    user_ids: props.recipients.map(recipient => recipient.id),
})

const audience = reactive(savedAudience())

const savedFingerprint = computed(() => {
    const saved = savedAudience()

    return fingerprint(saved.is_for_everyone, saved.user_ids)
})

const audienceChanged = computed(
    () => savedFingerprint.value !== fingerprint(audience.is_for_everyone, audience.user_ids)
)

/**
 * Volgt de server en niet elke herteking: een titel opslaan levert dezelfde
 * doelgroep op en laat een half gemaakte keuze hier met rust.
 */
watch(savedFingerprint, () => Object.assign(audience, savedAudience()))

function patch(...fields) {
    form.transform(data => Object.fromEntries(fields.map(field => [field, data[field]])))
        .patch(`/internalannouncements/${props.announcement.id}`, { preserveScroll: true })
}

/**
 * Na afloop terug naar wat de server ervan gemaakt heeft. Meestal is dat wat je
 * stuurde, maar niet altijd: wie al bevestigde blijft ontvanger, ook als je hem
 * eruit haalde. Dan hoort het keuzeveld dat te laten zien in plaats van te
 * blijven staan met een opslaanknop die niets meer te doen heeft.
 */
function saveAudience() {
    form.transform(() => ({
        is_for_everyone: audience.is_for_everyone,
        user_ids: audience.is_for_everyone ? [] : audience.user_ids,
    })).patch(`/internalannouncements/${props.announcement.id}`, {
        preserveScroll: true,
        onSuccess: () => Object.assign(audience, savedAudience()),
    })
}
</script>
