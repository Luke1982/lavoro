<template>
    <div class="flow-root rounded-lg transition-colors" :class="line.is_optional ? 'bg-sky-50/80 dark:bg-sky-900/15' : ''">
        <div class="flex flex-wrap md:flex-nowrap items-start gap-x-3 gap-y-2 px-3" :class="compact ? 'py-2' : 'py-3'">
            <div class="flex items-center gap-2 shrink-0" :class="compact ? '' : 'pt-3'">
                <button v-if="editable" type="button" class="draghandle cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-500 dark:text-slate-600"
                    aria-label="Verslepen">
                    <GripVerticalIcon class="size-4" />
                </button>
                <input v-if="line.is_optional" type="checkbox" :checked="line.is_chosen === true" :disabled="!editable"
                    class="size-4 rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue"
                    v-tooltip="line.is_chosen ? 'Telt mee in het totaal' : 'Telt niet mee in het totaal'"
                    @change="patch({ is_chosen: !line.is_chosen })" />
            </div>

            <div v-if="!compact"
                class="size-12 shrink-0 rounded-lg bg-white dark:bg-slate-900 ring-1 ring-gray-200 dark:ring-slate-700 flex items-center justify-center overflow-hidden">
                <img v-if="line.image" :src="imageFileUrl(line.image)" :alt="line.description" class="size-full object-contain" />
                <component :is="placeholderIcon" v-else class="size-5 text-gray-500 dark:text-slate-400" />
            </div>

            <div class="min-w-0 flex-1 basis-60" :class="compact ? '' : 'pt-0.5'">
                <div class="flex items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <input v-if="editable" v-model="form.description" @change="save('description')" aria-label="Omschrijving"
                            :class="[GHOST_FIELD, 'font-semibold text-gray-900 dark:text-slate-100']" />
                        <div v-else class="font-semibold text-gray-900 dark:text-slate-100 py-1">{{ line.description }}</div>
                        <template v-if="!compact && isText">
                            <TipTapEditor v-if="editable" v-model="form.details" compact class="mt-1" @blur="save('details')" />
                            <div v-else-if="line.details" class="rich-text text-sm text-gray-600 dark:text-slate-300" v-html="line.details" />
                        </template>
                        <template v-else-if="!compact">
                            <textarea v-if="editable" v-model="form.details" @change="save('details')" rows="1"
                                placeholder="Toelichting" aria-label="Toelichting"
                                :class="[GHOST_FIELD, 'text-xs text-gray-500 dark:text-slate-400 resize-none field-sizing-content']" />
                            <p v-else-if="line.details" class="text-xs text-gray-500 dark:text-slate-400 whitespace-pre-line">{{ line.details }}</p>
                        </template>
                    </div>
                    <span class="mt-1 shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="kind.badge">{{ kind.label }}</span>
                </div>
                <p v-for="error in form.errors" :key="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
            </div>

            <div class="flex items-start gap-2 w-full md:w-auto justify-end shrink-0">
                <template v-if="!isText">
                    <div :class="COLUMNS.quantity">
                        <input v-if="editable && !compact" v-model="form.quantity" type="number" step="any" min="0" @change="save('quantity')"
                            :class="BOXED_FIELD" aria-label="Aantal" />
                        <div v-else class="py-1.5 text-right tabular-nums">{{ nlQuantity(line.quantity) }}</div>
                    </div>
                    <div :class="COLUMNS.unit">
                        <select v-if="editable && !compact" v-model="form.unit" @change="save('unit')" :class="BOXED_FIELD" aria-label="Eenheid">
                            <option :value="null">–</option>
                            <option v-for="unit in unitOptions" :key="unit" :value="unit">{{ unit }}</option>
                        </select>
                        <div v-else class="py-1.5 text-gray-500">{{ line.unit }}</div>
                    </div>
                    <div :class="COLUMNS.price">
                        <CurrencyInput v-if="editable && !compact" v-model="form.unit_price" @change="save('unit_price')"
                            aria-label="Prijs per stuk" />
                        <div v-else class="py-1.5 text-right tabular-nums">{{ nlCurrency(line.unit_price) }}</div>
                        <div v-if="seeMargins && !compact" class="mt-1 flex items-center gap-1 text-[11px] text-gray-500">
                            <span>inkoop</span>
                            <input v-if="editable" v-model="form.cost_price" type="number" step="0.01" @change="save('cost_price')"
                                :class="[GHOST_FIELD, NO_SPIN, 'text-right text-[11px] py-0']" aria-label="Inkoopprijs" />
                            <span v-else class="tabular-nums">{{ line.cost_price === null ? '–' : nlCurrency(line.cost_price) }}</span>
                        </div>
                        <div v-if="(discounting || line.discount_percent) && !compact"
                            class="mt-1 flex items-center justify-end gap-1 text-[11px] text-gray-500">
                            <span>korting</span>
                            <input v-if="editable" v-model="form.discount_percent" type="number" step="any" min="0" max="100"
                                @change="save('discount_percent')" :class="[GHOST_FIELD, NO_SPIN, 'w-12 text-right text-[11px] py-0']"
                                aria-label="Korting in procenten" />
                            <span v-else class="tabular-nums">{{ nlQuantity(line.discount_percent) }}</span>%
                        </div>
                    </div>
                    <div :class="COLUMNS.vat">
                        <select v-if="editable && !compact" v-model.number="form.vat_percent" @change="save('vat_percent')" :class="BOXED_FIELD"
                            aria-label="Btw">
                            <option v-for="rate in vatRates" :key="rate" :value="rate">{{ rate }}%</option>
                        </select>
                        <div v-else class="py-1.5 text-gray-500">{{ nlQuantity(line.vat_percent) }}%</div>
                    </div>
                    <div class="py-1.5 text-right font-bold tabular-nums" :class="[COLUMNS.total, line.is_optional && !line.is_chosen ? 'text-gray-400' : '']">
                        {{ nlCurrency(line.net) }}
                    </div>
                </template>
                <div :class="COLUMNS.actions" class="flex justify-end pt-1">
                    <DropdownMenu v-if="editable" placement="bottom-end" width-class="w-56" title="Meer"
                        button-class="rounded-md p-1 text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-slate-800">
                        <template #button>
                            <EllipsisVerticalIcon class="size-4" />
                        </template>
                        <MenuItem v-if="!isText" v-slot="{ active }">
                            <button type="button" :class="menuItem(active)" @click="patch({ is_optional: !line.is_optional })">
                                <StarIcon class="size-4" />
                                {{ line.is_optional ? 'Niet meer optioneel' : 'Optioneel maken' }}
                            </button>
                        </MenuItem>
                        <MenuItem v-if="!isText" v-slot="{ active }">
                            <button type="button" :class="menuItem(active)" @click="discounting = true">
                                <PercentIcon class="size-4" />
                                Korting geven
                            </button>
                        </MenuItem>
                        <MenuItem v-slot="{ active }">
                            <button type="button" :class="menuItem(active, true)" @click="remove">
                                <Trash2Icon class="size-4" />
                                Verwijderen{{ line.children?.length ? ', met de materialen' : '' }}
                            </button>
                        </MenuItem>
                    </DropdownMenu>
                </div>
            </div>
        </div>
        <slot />
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { MenuItem } from '@headlessui/vue'
import {
    AlignLeftIcon, EllipsisVerticalIcon, GripVerticalIcon, PackageIcon, PercentIcon, StarIcon, Trash2Icon, WrenchIcon,
} from '@lucide/vue'
import { BOXED_FIELD, COLUMNS, GHOST_FIELD, KINDS, NO_SPIN, menuItem, unitChoices } from '@/Components/Quotes/quoteColumns'
import { useSaveField } from '@/Components/Quotes/useSaveField'
import CurrencyInput from '@/Components/UI/CurrencyInput.vue'
import DropdownMenu from '@/Components/UI/DropdownMenu.vue'
import TipTapEditor from '@/Components/UI/TipTapEditor.vue'
import { imageFileUrl } from '@/Utilities/imageFiles'
import { nlCurrency, nlQuantity } from '@/Utilities/Utilities'

/**
 * One line at the top of a quote, edited where it stands: a field is saved the
 * moment it is left. The materials that came along with a product are drawn
 * below it, through the slot. An optional line is tinted and carries a box:
 * ticked, it counts in the total and the customer finds it ticked.
 *
 * The page keys a row on its id and its updated_at, so a row whose line changed
 * on the server, also by a listener, starts again from what the server holds.
 */
const props = defineProps({
    quoteId: { type: Number, required: true },
    line: { type: Object, required: true },
    vatRates: { type: Array, required: true },
    units: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
    seeMargins: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
})

const isText = computed(() => props.line.kind === 'text')
const kind = computed(() => KINDS[props.line.is_optional ? 'optional' : props.line.kind] ?? KINDS.free)
const placeholderIcon = computed(() => ({ product: PackageIcon, material: PackageIcon, text: AlignLeftIcon })[props.line.kind] ?? WrenchIcon)
const unitOptions = computed(() => unitChoices(props.units, props.line.unit))
const url = computed(() => `/quotes/${props.quoteId}/lines/${props.line.id}`)

const form = useForm({
    description: props.line.description,
    details: props.line.details,
    quantity: props.line.quantity,
    unit: props.line.unit,
    unit_price: props.line.unit_price,
    cost_price: props.line.cost_price ?? null,
    vat_percent: props.line.vat_percent,
    discount_percent: props.line.discount_percent,
})

const discounting = ref(false)

const save = useSaveField(form, () => url.value, () => props.line)

function patch(changes) {
    router.patch(url.value, changes, { preserveScroll: true })
}

function remove() {
    const along = props.line.children?.length ? ` en de ${props.line.children.length} materialen eronder` : ''
    if (!confirm(`${props.line.description}${along} van de offerte halen?`)) return
    router.delete(url.value, { preserveScroll: true })
}
</script>
