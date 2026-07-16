<template>
    <ModalDialog :open="open" :title="title" max-width-class="sm:max-w-2xl" @update:open="onOpenChange">
        <div v-if="loading" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
            Bezig met ophalen van de gevolgen...
        </div>

        <div v-else class="space-y-5 text-sm">
            <p class="text-gray-700 dark:text-gray-300">
                Je verplaatst deze {{ subjectLabel }} naar <strong>{{ newCustomerName }}</strong>.
                De onderstaande machines gaan mee naar de nieuwe klant.
            </p>

            <div>
                <h3 class="mb-2 font-medium text-gray-900 dark:text-gray-100">
                    Machines die meegaan ({{ preview.assets.length }})
                </h3>
                <ul class="max-h-40 space-y-1 overflow-y-auto rounded-md bg-gray-50 p-3 dark:bg-gray-800">
                    <li v-for="asset in preview.assets" :key="asset.id"
                        class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                        <span :class="asset.is_child ? 'pl-4 text-gray-500 dark:text-gray-400' : 'font-medium'">
                            {{ asset.label }}
                        </span>
                        <BadgeComponent v-if="asset.is_child" text="onderdeel" color="gray" />
                    </li>
                </ul>
                <p v-if="hasChildren" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Onderdelen horen bij hun machine en gaan altijd mee.
                </p>
            </div>

            <div v-if="preview.locations.length">
                <h3 class="mb-2 font-medium text-gray-900 dark:text-gray-100">Locaties</h3>
                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                    De huidige locaties horen bij de oude klant. Kies waar de machines bij
                    {{ newCustomerName }} komen te staan, of laat leeg.
                </p>
                <div v-for="location in preview.locations" :key="location.id" class="mb-3">
                    <ComboBox :options="targetLocationOptions" v-model="locationMap[location.id]"
                        :label="location.label" placeholder="Geen locatie" />
                </div>
            </div>

            <div v-if="preview.contracts.length"
                class="rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950">
                <h3 class="mb-1 font-medium text-amber-900 dark:text-amber-200">Let op</h3>
                <p class="text-amber-800 dark:text-amber-300">
                    De machines worden losgekoppeld van deze contracten van de oude klant:
                </p>
                <ul class="mt-2 list-inside list-disc text-amber-800 dark:text-amber-300">
                    <li v-for="contract in preview.contracts" :key="contract.id">{{ contract.label }}</li>
                </ul>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="cancel"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    Annuleren
                </button>
                <button type="button" :disabled="loading" @click="confirm"
                    class="rounded-md bg-lavoro-blue px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50">
                    Machines meeverhuizen
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'
import BadgeComponent from './BadgeComponent.vue'
import ComboBox from './ComboBox.vue'
import ModalDialog from './ModalDialog.vue'

const props = defineProps({
    open: { type: Boolean, required: true },
    context: { type: String, required: true },
    subjectId: { type: [String, Number], required: true },
    customerId: { type: [String, Number], default: null },
    newCustomerName: { type: String, default: '' },
})

const emit = defineEmits(['update:open', 'confirm', 'cancel'])

const loading = ref(false)
const locationMap = ref({})
const preview = ref({ assets: [], locations: [], contracts: [], target_locations: [] })

const subjectLabel = computed(() => ({
    contract: 'contract',
    serviceorder: 'werkbon',
    asset: 'machine',
}[props.context] ?? 'record'))

const hasChildren = computed(() => preview.value.assets.some(asset => asset.is_child))

const targetLocationOptions = computed(() =>
    preview.value.target_locations.map(location => ({ id: location.id, name: location.label }))
)

async function load() {
    loading.value = true
    locationMap.value = {}

    try {
        await axios.get('sanctum/csrf-cookie')
        const response = await axios.post('/assets/transfer-preview', {
            context: props.context,
            id: props.subjectId,
            customer_id: props.customerId,
        })
        preview.value = response.data
    } catch {
        emit('cancel')
        emit('update:open', false)
    } finally {
        loading.value = false
    }
}

watch(() => props.open, isOpen => {
    if (isOpen && props.customerId) {
        load()
    }
})

function onOpenChange(value) {
    if (!value) {
        cancel()
    }
}

function cancel() {
    emit('cancel')
    emit('update:open', false)
}

function confirm() {
    const map = {}
    Object.entries(locationMap.value).forEach(([old_id, new_id]) => {
        if (new_id) {
            map[old_id] = new_id
        }
    })

    emit('confirm', { asset_strategy: 'transfer', location_map: map })
    emit('update:open', false)
}
</script>
