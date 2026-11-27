<template>
    <BoxComponent data-tour="quote.live">
        <SectionHeader :icon="EyeIcon" title="Live voorbeeld" chapter="documents" flush
            subtitle="Zoals de klant de offerte leest. Wat je bewaart, staat er meteen in.">
            <template #actions>
                <button type="button" class="shrink-0 rounded-md p-1 text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-slate-800"
                    :aria-expanded="open" :aria-label="open ? 'Voorbeeld inklappen' : 'Voorbeeld uitklappen'" @click="open = !open">
                    <ChevronDownIcon class="size-5 transition-transform" :class="open ? 'rotate-180' : ''" />
                </button>
            </template>
        </SectionHeader>

        <FoldPanel :open="open">
            <p v-if="example" class="mt-3 text-xs italic text-amber-700 dark:text-amber-400">
                Voorbeeld uit de rondleiding: deze regels worden niet bewaard.
            </p>
            <div class="mt-4 max-h-[36rem] overflow-y-auto rounded-xl bg-white dark:bg-slate-900 p-4 ring-1 ring-slate-200 dark:ring-slate-700">
                <QuoteCustomerDocument :quote="document.quote" :lines="example?.lines ?? document.lines"
                    :totals="example?.totals ?? document.totals" :layout="document.layout" :terms="document.terms"
                    :withdrawal="document.withdrawal" :provisional_explanation="document.provisional_explanation" />
            </div>
        </FoldPanel>
    </BoxComponent>
</template>

<script setup>
import { ref, watch } from 'vue'
import { ChevronDownIcon, EyeIcon } from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import QuoteCustomerDocument from '@/Components/Quotes/QuoteCustomerDocument.vue'
import FoldPanel from '@/Components/UI/FoldPanel.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import { temporarily, useTour } from '@/Composables/useTour'
import { browserStorage } from '@/Utilities/browserStorage'

/**
 * The quote beside itself as the customer reads it, from what QuoteForCustomer
 * gives: every saved change reloads the page and so this too. While the
 * rondleiding shows example lines, it shows those and their totals. It folds
 * away, and this browser remembers whether it was open; the rondleiding opens
 * it to show it, and folds it again if it was shut.
 */
defineProps({
    /** QuoteForCustomer::page: the heading, texts, lines, totals, layout, terms and right to withdraw. */
    document: { type: Object, required: true },
    /** The rondleiding's example, { lines, totals }, while it shows one. */
    example: { type: Object, default: null },
})

const STORAGE_KEY = 'lavoro_quote_preview_open'

const open = ref(browserStorage.get(STORAGE_KEY) !== '0')

watch(open, (value) => browserStorage.set(STORAGE_KEY, value ? '1' : '0'))

useTour('offerte', { openPreview: () => temporarily(open, true) })
</script>
