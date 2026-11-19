<template>
    <ModalDialog :open="open" :title="title" max-width-class="sm:max-w-2xl" @update:open="onOpenChange">
        <div v-if="loading" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
            Bezig met ophalen van de gevolgen...
        </div>

        <div v-else class="space-y-5 text-sm">
            <fieldset v-if="offersChoice" class="flex flex-col gap-2.5">
                <label v-for="choice in CHOICES" :key="choice.value"
                    :class="[strategy === choice.value
                        ? 'border-lavoro-blue bg-lavoro-lightblue/60 shadow-sm dark:bg-blue-950/40'
                        : 'border-gray-200 bg-white hover:border-gray-300 dark:border-slate-700 dark:bg-slate-800 dark:hover:border-slate-600',
                        'flex cursor-pointer items-start gap-3 rounded-xl border-2 px-4 py-3 transition duration-200 active:scale-[0.99] motion-reduce:transition-none']">
                    <input type="radio" name="customer-transfer-strategy" :value="choice.value" v-model="strategy"
                        class="mt-0.5 size-4 accent-lavoro-blue" />
                    <span class="flex flex-col gap-0.5">
                        <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ choice.label }}</span>
                        <span class="text-sm text-gray-600 dark:text-slate-300">{{ choice.description }}</span>
                    </span>
                </label>
            </fieldset>

            <p v-else class="text-gray-700 dark:text-gray-300">
                Je verplaatst {{ subjectLabel }} naar <strong>{{ newCustomerName }}</strong>.
                De onderstaande machines gaan mee naar de nieuwe klant.
            </p>

            <div v-if="strategy === 'detach'">
                <h3 class="mb-2 font-medium text-gray-900 dark:text-gray-100">
                    Machines die van het contract afgaan ({{ detachedAssets.length }})
                </h3>
                <ul class="max-h-40 space-y-1 overflow-y-auto rounded-md bg-gray-50 p-3 dark:bg-gray-800">
                    <li v-for="asset in detachedAssets" :key="asset.id" class="font-medium text-gray-700 dark:text-gray-300">
                        {{ asset.label }}
                    </li>
                </ul>
            </div>

            <template v-else>
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
            </template>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="cancel"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    Annuleren
                </button>
                <button type="button" :disabled="loading" @click="confirm"
                    class="rounded-md bg-lavoro-blue px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50">
                    {{ strategy === 'detach' ? 'Contract verhuizen' : 'Machines meeverhuizen' }}
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
import { subjectPhrase } from '@/Utilities/Utilities'

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
const strategy = ref('transfer')
const preview = ref({ assets: [], locations: [], contracts: [], target_locations: [], attached: [] })

const MODELS = { contract: 'MaintenanceContract', serviceorder: 'ServiceOrder', asset: 'Asset' }

const CHOICES = [
    { value: 'transfer', label: 'Machines gaan mee', description: 'De machines verhuizen mee naar de nieuwe klant.' },
    { value: 'detach', label: 'Alleen het contract', description: 'De machines blijven bij de oude klant en gaan van het contract af.' },
]

const subjectLabel = computed(() => subjectPhrase(MODELS[props.context]) ?? 'dit record')

const offersChoice = computed(() => props.context === 'contract')

const detachedAssets = computed(() => preview.value.assets.filter(asset => preview.value.attached.includes(asset.id)))

const title = computed(() => 'Verhuizen naar ' + (props.newCustomerName || 'een andere klant'))

const hasChildren = computed(() => preview.value.assets.some(asset => asset.is_child))

const targetLocationOptions = computed(() =>
    preview.value.target_locations.map(location => ({ id: location.id, name: location.label }))
)

async function load() {
    loading.value = true
    locationMap.value = {}
    strategy.value = 'transfer'

    try {
        await axios.get('/sanctum/csrf-cookie')
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
    const location_map = strategy.value === 'transfer'
        ? Object.fromEntries(Object.entries(locationMap.value).filter(([, new_id]) => new_id))
        : {}

    emit('confirm', { asset_strategy: strategy.value, location_map })
    emit('update:open', false)
}
</script>
