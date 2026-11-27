<template>
    <div class="flex items-center gap-3">
        <QuoteItemPhoto :image="item.image" :alt="item.name" />

        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <span class="truncate font-semibold">{{ item.name }}</span>
                <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="active ? 'bg-white/20 text-white' : kind.badge">
                    {{ kind.label }}
                </span>
            </div>
            <p v-if="facts.length" class="truncate text-xs" :class="muted">{{ facts.join(' · ') }}</p>
            <p v-if="item.description" class="truncate text-xs" :class="muted">{{ item.description }}</p>
        </div>

        <div class="shrink-0 text-right">
            <div class="font-semibold tabular-nums">{{ nlCurrency(item.price) }}</div>
            <div class="text-xs" :class="muted">per {{ item.unit || 'stuk' }}</div>
            <div v-if="item.defaults?.length" class="mt-0.5 text-[0.65rem] font-medium" :class="active ? 'text-indigo-100' : 'text-lavoro-blue dark:text-blue-300'">
                +{{ item.defaults.length }} {{ item.defaults.length === 1 ? 'materiaal' : 'materialen' }}
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import QuoteItemPhoto from '@/Components/Quotes/QuoteItemPhoto.vue'
import { KINDS } from '@/Components/Quotes/quoteColumns'
import { nlCurrency, nlQuantity } from '@/Utilities/Utilities'

/**
 * One result of the catalogue search on a quote: the photo, the name with what
 * kind it is, what tells two alike apart (a product's type and article number;
 * a material's code and stock), the start of its description, and the
 * sale price per unit, with how many materials a product brings along.
 */
const props = defineProps({
    item: { type: Object, required: true },
    /** Whether the row is highlighted in the list, which turns it dark. */
    active: { type: Boolean, default: false },
})

const kind = computed(() => KINDS[props.item.type] ?? KINDS.material)
const muted = computed(() => props.active ? 'text-indigo-100' : 'text-gray-500 dark:text-slate-400')

const facts = computed(() => [
    props.item.type_name,
    props.item.part_no && `art.nr. ${props.item.part_no}`,
    props.item.code && `code ${props.item.code}`,
    typeof props.item.stock === 'number' && `${nlQuantity(props.item.stock)} op voorraad`,
].filter(Boolean))
</script>
