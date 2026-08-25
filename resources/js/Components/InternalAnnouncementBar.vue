<template>
    <Transition enter-active-class="duration-300 ease-out" enter-from-class="translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100" leave-active-class="duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-full opacity-0">
        <!--
            Zelfde plek als de andere balken onderin: naast het menu en niet
            eronder, en op een telefoon boven de tabbalk. Wel hoger in de stapel
            dan een flitsbericht, want dat verdwijnt vanzelf en dit niet.

            De omhulling loopt over de volle breedte — de ruimte links is padding
            en geen marge — en ligt boven de zijbalk. Zonder pointer-events-none
            vangt dat doorzichtige stuk de klikken op Uitloggen op. Alleen de
            kaart zelf hoort te reageren, net als bij GlobalNotification.
        -->
        <div v-if="announcement"
            class="pointer-events-none fixed inset-x-0 bottom-[6.5rem] z-[10000] transition-[padding-left,transform,opacity] lg:bottom-0"
            :class="collapsed ? 'lg:pl-[4.5rem]' : 'lg:pl-72'" role="region" aria-label="Aankondiging">
            <div
                class="pointer-events-auto flex flex-col gap-3 bg-lavoro-green px-4 py-4 shadow-[0_-8px_30px_rgba(2,6,23,0.35)] sm:flex-row sm:items-center sm:justify-between sm:gap-6">
                <div class="flex min-w-0 items-start gap-3 text-gray-900">
                    <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-gray-900/10">
                        <Megaphone class="size-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 max-h-40 overflow-y-auto">
                        <p class="break-words text-sm font-semibold">{{ announcement.title }}</p>
                        <p class="mt-0.5 whitespace-pre-line break-words text-sm text-gray-900/75">{{ announcement.body }}</p>
                    </div>
                </div>
                <button type="button" :disabled="acknowledging"
                    class="shrink-0 self-end rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 sm:self-auto"
                    @click="acknowledge">
                    Begrepen
                </button>
            </div>
        </div>
    </Transition>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { Megaphone } from '@lucide/vue'
import { useMenu } from '@/Composables/useMenu.js'

/**
 * Toont de ene aankondiging die deze gebruiker nog moet bevestigen. Welke dat is
 * bepaalt de server; hier staat alleen wat er getekend wordt en wat er gebeurt
 * bij Begrepen. Na het bevestigen komt de volgende vanzelf in dezelfde prop,
 * dus dit onderdeel houdt zelf geen lijst bij.
 *
 * Geen sluitkruis, met opzet. Een aankondiging die je kunt wegklikken zonder te
 * bevestigen is een melding, en die hebben we al.
 */
const page = usePage()
const { collapsed } = useMenu()

const announcement = computed(() => page.props.pendingAnnouncement ?? null)
const acknowledging = ref(false)

function acknowledge() {
    if (!announcement.value || acknowledging.value) return

    acknowledging.value = true

    router.post(`/internalannouncements/${announcement.value.id}/acknowledge`, {}, {
        preserveScroll: true,
        onFinish: () => { acknowledging.value = false },
    })
}
</script>
