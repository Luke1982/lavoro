<template>
    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <VisitCard :service-order-id="serviceOrder.id" :customer="serviceOrder.customer"
            :initial-events="events" :fallback-location="mapLocation"
            @times-updated="$emit('times-updated', $event)" />

        <WorkToDo :service-order-id="serviceOrder.id" :servicejobs="serviceOrder.servicejobs"
            :tickets="serviceOrder.tickets" :task-instances="serviceOrder.task_instances"
            :hidden-task-count="hiddenTaskCount" :is-closed="serviceOrder.is_closed" :type="serviceOrder.type" />

        <MaterialsCompact :service-order-id="serviceOrder.id" :materials="materials"
            :freeform-materials="freeformMaterials" :all-materials="allMaterials"
            :materials-use-ajax="materialsUseAjax" :categories="materialCategories" :usage-units="materialUsageUnits"
            :is-closed="serviceOrder.is_closed" :sent-to-administration="serviceOrder.sent_to_administration"
            :type="serviceOrder.type" />

        <AudienceBox title="Opmerkingen" :icon="MessageSquare" chapter="remarks"
            :internal-count="serviceOrder.internal_remarks.length" :external-count="serviceOrder.remarks.length">
            <template #default="{ internal }">
                <RemarksComponent :key="side(internal)" bare remarkable-type="App\Models\ServiceOrder"
                    :remarkable-id="serviceOrder.id" :disabled="serviceOrder.is_closed" :internal="internal"
                    :comments="remarks(internal)" />
                <p v-if="serviceOrder.is_closed && !remarks(internal).length"
                    class="text-sm text-gray-400 dark:text-slate-500">Geen opmerkingen.</p>
            </template>
        </AudienceBox>

        <AudienceBox title="Foto's" :icon="Camera" chapter="photos" initial="external"
            :internal-count="serviceOrder.internal_images.length" :external-count="serviceOrder.images.length">
            <template #default="{ internal }">
                <ImageUploadComponent :key="side(internal)" bare :existing="images(internal)"
                    :imageable-id="serviceOrder.id" imageable-type="App\Models\ServiceOrder" :internal="internal" />
            </template>
        </AudienceBox>

        <DocumentUploadComponent :existing="serviceOrder.documents" :documentable-id="serviceOrder.id"
            documentable-type="\App\Models\ServiceOrder" />

        <div v-if="finish" class="h-20" aria-hidden="true" />

        <FinishBar v-if="finish" :label="finish.label" :icon="finish.icon" :tone="finish.tone"
            @click="$emit(finish.emits)" />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Camera, Check, Eye, MessageSquare, Signature } from '@lucide/vue'
import { hasPermission } from '@/Utilities/Utilities'
import RemarksComponent from '@/Components/RemarksComponent.vue'
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue'
import DocumentUploadComponent from '@/Components/DocumentUploadComponent.vue'
import VisitCard from '@/Components/ServiceOrders/Mechanic/VisitCard.vue'
import WorkToDo from '@/Components/ServiceOrders/Mechanic/WorkToDo.vue'
import MaterialsCompact from '@/Components/ServiceOrders/Mechanic/MaterialsCompact.vue'
import AudienceBox from '@/Components/ServiceOrders/Mechanic/AudienceBox.vue'
import FinishBar from '@/Components/ServiceOrders/Mechanic/FinishBar.vue'

/**
 * De werkbon zoals een monteur hem op locatie gebruikt: waar en wanneer, wat er
 * moet gebeuren, wat er gebruikt is, opmerkingen en foto's, en onderin de knop
 * om af te ronden. Wat met beheer te maken heeft blijft in de standaardweergave.
 *
 * Afronden zelf gebeurt op de pagina eromheen, die het venster met het overzicht
 * en de handtekening ook voor de standaardweergave beheert.
 */
const props = defineProps({
    serviceOrder: { type: Object, required: true },
    events: { type: Array, default: () => [] },
    mapLocation: { type: Object, default: () => ({ address: null, source: null }) },
    materials: { type: Array, default: () => [] },
    freeformMaterials: { type: Array, default: () => [] },
    allMaterials: { type: Array, default: () => [] },
    materialsUseAjax: { type: Boolean, default: false },
    materialCategories: { type: Array, default: () => [] },
    materialUsageUnits: { type: Array, default: () => [] },
    hiddenTaskCount: { type: Number, default: 0 },
    isSigned: { type: Boolean, default: false },
    closedStageId: { type: [Number, null], default: null },
})

defineEmits(['times-updated', 'open-signoff', 'close-service-order'])

/**
 * Beide kanten van een blok zijn aparte lijsten, en wat er één toont wordt bij
 * het omschakelen opnieuw opgebouwd: opmerkingen en foto's leggen bij hun
 * ontstaan vast aan welke kant ze toevoegen.
 */
const side = (internal) => (internal ? 'internal' : 'external')
const remarks = (internal) => (internal ? props.serviceOrder.internal_remarks : props.serviceOrder.remarks)
const images = (internal) => (internal ? props.serviceOrder.internal_images : props.serviceOrder.images)

/**
 * Wat de knop onderin doet hangt af van hoe ver de werkbon is: eerst laten
 * tekenen, dan sluiten, en daarna alleen nog het overzicht bekijken.
 */
const finish = computed(() => {
    if (props.serviceOrder.is_closed) {
        return { label: 'Bekijk overzicht', icon: Eye, tone: 'neutral', emits: 'open-signoff' }
    }

    if (!hasPermission('serviceorder.close')) return null

    if (!props.isSigned) {
        return { label: 'Vraag handtekening aan de klant', icon: Signature, tone: 'blue', emits: 'open-signoff' }
    }

    if (props.closedStageId === null) {
        return { label: 'Bekijk overzicht', icon: Eye, tone: 'neutral', emits: 'open-signoff' }
    }

    return { label: 'Werkbon afronden', icon: Check, tone: 'green', emits: 'close-service-order' }
})
</script>
