<template>
    <DropdownMenu v-if="options.length" placement="bottom-end" width-class="w-72" title="Keuringen voor meerdere machines"
        button-class="inline-flex items-center gap-1.5 rounded border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 sm:py-2">
        <template #button>
            <LayersIcon class="size-4" />
            <span class="hidden sm:inline">Meerdere machines</span>
        </template>
        <MenuItem v-for="option in options" :key="option.key" v-slot="{ active }">
            <button type="button" @click="confirming = option" :class="[
                'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm text-gray-700 dark:text-slate-200',
                active ? 'bg-gray-50 dark:bg-slate-700' : '',
            ]">
                <span class="truncate">{{ option.label }}</span>
                <span class="flex-none text-xs text-gray-400 dark:text-slate-500">{{ option.count }}</span>
            </button>
        </MenuItem>
    </DropdownMenu>

    <ModalDialog :open="!!confirming" title="Keuringen aanmaken?" max-width-class="sm:max-w-md"
        @update:open="value => { if (!value) confirming = null }">
        <p v-if="confirming" class="text-sm text-gray-600 dark:text-slate-300">
            {{ confirming.count === 1 ? 'Eén machine' : `${confirming.count} machines` }} {{ confirming.where }}
            {{ confirming.count === 1 ? 'krijgt' : 'krijgen' }} een keuring op deze werkbon. Onderdelen krijgen er een
            gecombineerde keuring bij. Machines die al een keuring op deze werkbon hebben, slaan we over.
        </p>
        <template #footer>
            <div class="flex justify-end gap-3">
                <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="confirming = null">
                    Annuleren
                </button>
                <button type="button" :disabled="sending" @click="create"
                    class="rounded-md bg-lavoro-blue px-4 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
                    Keuringen aanmaken
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { MenuItem } from '@headlessui/vue'
import { Layers as LayersIcon } from '@lucide/vue'
import DropdownMenu from '@/Components/UI/DropdownMenu.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'

/**
 * Keuringen for many machines of the werkbon's customer at once: all of its active
 * machines, or those at one location. Each choice says how many machines still
 * have no keuring on this werkbon, and nothing happens before it is confirmed.
 */
const props = defineProps({
    serviceOrderId: { type: Number, required: true },
    /** The customer's machines with their parts, as the werkbon page has them. */
    assets: { type: Array, default: () => [] },
    /** Machines that already have a keuring on this werkbon. */
    presentAssetIds: { type: Array, default: () => [] },
})

const confirming = ref(null)
const sending = ref(false)

const waiting = computed(() => props.assets.filter(asset => !asset.parent_asset_id && asset.status === 'Actief'
    && !props.presentAssetIds.includes(asset.id)))

const options = computed(() => {
    if (!waiting.value.length) return []

    const locations = new Map()

    waiting.value.filter(asset => asset.location_id).forEach(asset => {
        const seen = locations.get(asset.location_id)
        locations.set(asset.location_id, {
            key: 'location:' + asset.location_id,
            label: asset.linked_location?.title ?? 'Locatie',
            where: 'op ' + (asset.linked_location?.title ?? 'deze locatie'),
            location_id: asset.location_id,
            count: (seen?.count ?? 0) + 1,
        })
    })

    return [
        { key: 'all', label: 'Alle machines van de klant', where: 'van deze klant', location_id: null, count: waiting.value.length },
        ...[...locations.values()].sort((a, b) => a.label.localeCompare(b.label)),
    ]
})

function create() {
    sending.value = true
    router.post(`/serviceorders/${props.serviceOrderId}/servicejobs`, { location_id: confirming.value.location_id }, {
        preserveScroll: true,
        onFinish: () => {
            sending.value = false
            confirming.value = null
        },
    })
}
</script>
