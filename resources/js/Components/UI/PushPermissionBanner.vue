<template>
    <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-full opacity-0"
    >
        <div
            v-if="can_ask"
            class="fixed bottom-0 inset-x-0 z-40 flex items-center justify-between gap-3 bg-slate-800 px-4 py-3 shadow-lg"
        >
            <div class="flex items-center gap-2 text-white">
                <BellAlertIcon class="size-5 shrink-0" />
                <span class="text-sm font-medium">Meldingen op dit apparaat ontvangen?</span>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    :disabled="asking"
                    class="rounded-md bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm disabled:opacity-60"
                    @click="allow"
                >
                    Toestaan
                </button>
                <button type="button" class="text-white/70 hover:text-white" @click="dismiss">
                    <XMarkIcon class="size-5" />
                </button>
            </div>
        </div>
    </Transition>
</template>

<script setup>
import { ref } from 'vue'
import { BellAlertIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { useWebPush } from '@/Composables/useWebPush.js'

/**
 * Vraagt om toestemming na een klik, niet vanzelf bij het laden: Safari geeft
 * de vraag alleen door als er een handeling van de gebruiker achter zit, en een
 * browser die er ongevraagd om gevraagd wordt onthoudt dat als een weigering.
 *
 * Staat los van het belletje in het menu, zodat die verbouwing dit kan
 * verplaatsen zonder er iets aan te veranderen.
 */
const { can_ask, enable, dismiss } = useWebPush()

const asking = ref(false)

const allow = async () => {
    asking.value = true

    try {
        await enable()
    } finally {
        asking.value = false
    }
}
</script>
